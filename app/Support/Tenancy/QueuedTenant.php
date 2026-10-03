<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Business;
use App\Models\Organization;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use WeakMap;

/**
 * Carries the tenant into queued jobs (ADR 0006). A job runs in the tenant of
 * the code that dispatched it: the organization and, if set, the business.
 * It carries no user rights (a job acts for no user, and a delayed job must
 * not keep rights revoked since) and no bypass() (code that spans tenants
 * calls it itself, so every such place stays searchable). A job whose tenant
 * is gone, suspended or archived by the time it runs fails, once, like ResolveTenant
 * refuses a user there.
 *
 * Sync jobs get the same treatment, then the dispatching code gets its own
 * context back, so tests on the sync queue behave like a worker. Event::fake()
 * without a list swallows JobProcessing and JobAttempted, so sync jobs would
 * then run in the caller's context: fake only the events a test asserts.
 *
 * Laravel restores a job's models without scopes (newQueryForRestoration), so
 * a job dispatched without a tenant still gets them and can call bypass();
 * relations eager-loaded on them are restored in the job's tenant, so they are
 * empty without one. The job's failed() handler runs in that tenant too.
 *
 * The tenant is read when the payload is built. That is at dispatch for every
 * configured connection; a sync job marked afterCommit builds it at commit.
 * The deferred, background and failover drivers build it later or in another
 * process, so config/queue.php does not offer them.
 */
final class QueuedTenant
{
    public const string PAYLOAD_KEY = 'punchcard:tenant';

    /** @var WeakMap<Job, TenantContext> The context around each running job. */
    private WeakMap $saved;

    public function __construct()
    {
        $this->saved = new WeakMap;
    }

    /**
     * Added to every job payload when it is built.
     *
     * @return array<string, array{organization: int, business: int|null}>
     */
    public function payload(): array
    {
        $context = $this->context();
        $organizationId = $context->organizationId();

        return $organizationId === null ? [] : [self::PAYLOAD_KEY => [
            'organization' => $organizationId,
            'business' => $context->businessId(),
        ]];
    }

    /**
     * Before a job runs: saves the current context and enters the job's tenant.
     * A tenant that is gone, suspended, archived or malformed fails the job without
     * retries (it will not come back) rather than running it in a wider or
     * empty scope.
     */
    public function enter(JobProcessing $event): void
    {
        $job = $event->job;
        $context = $this->context();
        $this->saved[$job] = $context->snapshot();
        $context->restore(new TenantContext);

        $payload = $job->payload();

        if (! array_key_exists(self::PAYLOAD_KEY, $payload)) {
            return;
        }

        try {
            [$organization, $business] = $this->resolve($payload[self::PAYLOAD_KEY]);
        } catch (TenantUnavailable $exception) {
            $job->fail($exception);

            throw $exception;
        }

        $context->set($organization, $business);
    }

    /** After a job ran or failed: gives the code around it its context back. */
    public function leave(JobAttempted $event): void
    {
        if (! isset($this->saved[$event->job])) {
            return;
        }

        $this->context()->restore($this->saved[$event->job]);
        unset($this->saved[$event->job]);
    }

    /**
     * @return array{0: Organization, 1: Business|null}
     */
    private function resolve(mixed $tenant): array
    {
        $organizationId = is_array($tenant) ? $tenant['organization'] ?? null : null;
        $businessId = is_array($tenant) ? $tenant['business'] ?? null : null;

        if (! is_int($organizationId) || ($businessId !== null && ! is_int($businessId))) {
            throw new TenantUnavailable('The job payload has a malformed tenant.');
        }

        return $this->context()->bypass(function () use ($organizationId, $businessId): array {
            if ($businessId === null) {
                $organization = Organization::query()->operating()->find($organizationId);

                return $organization instanceof Organization
                    ? [$organization, null]
                    : throw new TenantUnavailable('The tenant this job was queued for no longer exists, or is suspended or archived.');
            }

            // An operating business (its organization not archived) makes the
            // organization available: no need for Organization::operating() too.
            $business = Business::query()->operating()->with('organization')->find($businessId);

            if (! $business instanceof Business) {
                throw new TenantUnavailable('The tenant this job was queued for no longer exists, or is suspended or archived.');
            }

            if ((int) $business->organization_id !== $organizationId) {
                throw new TenantUnavailable('The job payload has a malformed tenant.');
            }

            return [$business->organization, $business];
        });
    }

    /** TenantContext is scoped (one per request or job); this class is not. */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
