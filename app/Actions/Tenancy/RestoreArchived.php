<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\BusinessStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use LogicException;

/**
 * Reopens an archived organization, business or location (CHW-139): a
 * platform admin action only, in bypass(); tenants never un-archive. A
 * business goes back to Verified. Nothing else is reattached: the admin
 * assigns tags again and re-adds the business to its cards explicitly, and
 * restoring an organization leaves its businesses archived until restored.
 */
final readonly class RestoreArchived
{
    public function __construct(private TenantContext $context) {}

    public function handle(Organization|Business|Location $archived): void
    {
        if (! $this->context->isBypassed()) {
            throw new LogicException('Restoring an archive is a platform admin action, in TenantContext::bypass().');
        }

        $archived->forceFill($archived instanceof Business
            ? ['status' => BusinessStatus::Verified]
            : ['archived_at' => null])->save();
    }
}
