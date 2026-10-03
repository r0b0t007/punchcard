<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\Business;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Closes an organization (CHW-139): it is archived, every business and
 * location with it, and every stamper assignment ends, in five statements
 * whatever its size. Nobody resolves into it and nothing new happens there
 * (ArchivedSites); its cards and history stay as they were. A platform admin
 * action only, in bypass(); RestoreArchived undoes it, one level at a time.
 */
final readonly class ArchiveOrganization
{
    public function __construct(
        private TenantContext $context,
        private CloseSites $closeSites,
    ) {}

    public function handle(Organization $organization): void
    {
        if (! $this->context->isBypassed()) {
            throw new LogicException('Archiving an organization is a platform admin action, in TenantContext::bypass().');
        }

        DB::transaction(function () use ($organization): void {
            $this->closeSites->handle(
                'organization_id',
                $organization->id,
                Organization::query()->whereKey($organization->id),
                Business::query()->where('organization_id', $organization->id),
            );
            $organization->refresh();
        });
    }
}
