<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Location;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * A queued job that records the TenantContext it runs in, for QueuedTenantTest.
 */
final class TenantProbeJob implements ShouldQueue
{
    use Queueable;

    /** @var list<array{organization: int|null, business: int|null, org_admin: bool, role: string|null, bypassed: bool, locations: list<string>}> */
    public static array $seen = [];

    public function __construct(public bool $throw = false) {}

    /** Runs the database queue in-process until it is empty, like a worker would. */
    public static function workDatabaseQueue(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0]);
    }

    public function handle(TenantContext $context): void
    {
        self::$seen[] = [
            'organization' => $context->organizationId(),
            'business' => $context->businessId(),
            'org_admin' => $context->isOrgAdmin(),
            'role' => $context->businessRole()?->value,
            'bypassed' => $context->isBypassed(),
            'locations' => Location::query()->orderBy('name')->pluck('name')->all(),
        ];

        if ($this->throw) {
            throw new RuntimeException('Probe failed on purpose.');
        }
    }
}
