<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Tenancy\ArchiveBusiness;
use App\Actions\Tenancy\ArchiveOrganization;
use App\Enums\OrganizationType;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;

/**
 * "Cancel setup" in the wizard (CHW-31): a business abandoned before it is
 * set up is closed, never deleted (CHW-139), with the independent
 * organization the wizard made for it (only the business, in any other).
 * The archive Actions are the platform admin's, in bypass(): this runs them
 * so once the caller has checked it is the owner's own unfinished business. Its owner is then free of it: no wizard, and nothing
 * left to hand over before deleting the account. A business that finished
 * setup is never closed here.
 */
final readonly class CancelSetup
{
    public function __construct(
        private TenantContext $context,
        private ArchiveOrganization $archiveOrganization,
        private ArchiveBusiness $archiveBusiness,
    ) {}

    public function handle(Business $business): void
    {
        if ($business->onboarded_at !== null) {
            return;
        }

        $this->context->bypass(function () use ($business): void {
            $organization = $business->organization()->firstOrFail();

            if ($organization->type === OrganizationType::Independent) {
                $this->archiveOrganization->handle($organization);
            } else {
                $this->archiveBusiness->handle($business);
            }
        });
    }
}
