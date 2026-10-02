<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Business;
use App\Models\Organization;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Carries the tenant into queued jobs (ADR 0006). A job runs in the tenant of
 * the code that dispatched it: the organization and, if set, the business.
 * It carries no user rights (a job acts for no user, and a delayed job must
 * not keep rights revoked since) and no bypass() (code that spans tenants
 * calls it itself, so every such place stays searchable).
 *
 * Sync jobs get the same treatment, then the dispatching code gets its own
 * context back, so tests on the sync queue behave like a worker.
 */
final class QueuedTenant
{
    public const string PAYLOAD_KEY = 'punchcard:tenant';

    /** @var array<int, TenantContext> The context before each running job, by job object id. */
    private array $saved = [];

    /**
     * Added to every job payload when it is queued.
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
     * A job queued for a tenant that no longer exists fails, rather than running
     * against an empty scope.
     */
    public function enter(JobProcessing $event): void
    {
        $context = $this->context();
        $this->saved[spl_object_id($event->job)] = $context->snapshot();
        $context->restore(new TenantContext);

        $tenant = $event->job->payload()[self::PAYLOAD_KEY] ?? null;

        if (! is_array($tenant) || ! is_int($tenant['organization'] ?? null)) {
            return;
        }

        $organizationId = $tenant['organization'];
        $businessId = is_int($tenant['business'] ?? null) ? $tenant['business'] : null;

        [$organization, $business] = $context->bypass(fn (): array => [
            Organization::query()->find($organizationId),
            $businessId === null ? null : Business::query()->find($businessId),
        ]);

        if (! $organization instanceof Organization || ($businessId !== null && ! $business instanceof Business)) {
            throw new TenantUnavailable('The tenant this job was queued for no longer exists.');
        }

        $context->set($organization, $business);
    }

    /** After a job ran or failed: gives the code around it its context back. */
    public function leave(JobAttempted $event): void
    {
        $id = spl_object_id($event->job);

        if (! isset($this->saved[$id])) {
            return;
        }

        $this->context()->restore($this->saved[$id]);
        unset($this->saved[$id]);
    }

    /** TenantContext is scoped (one per request or job); this class is not. */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
