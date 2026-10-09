<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Tenancy\ArchiveOrganization;
use App\Enums\OrganizationType;
use App\Models\Business;
use App\Support\Tenancy\TenantContext;
use LogicException;

/**
 * "Cancel setup" in the wizard (CHW-31): a business abandoned before it is
 * set up is closed, never deleted (CHW-139), with the organization the
 * wizard made for it. Its owner is then free of it: no wizard, and nothing
 * left to hand over before deleting the account. A business that finished
 * setup is never closed here.
 */
final readonly class CancelSetup
{
    public function __construct(
        private TenantContext $context,
        private ArchiveOrganization $archiveOrganization,
    ) {}

    public function handle(Business $business): void
    {
        if ($business->onboarded_at !== null) {
            return;
        }

        $this->context->bypass(function () use ($business): void {
            $organization = $business->organization()->firstOrFail();

            if ($organization->type !== OrganizationType::Independent) {
                throw new LogicException('Only a business the wizard started, in its own independent organization, is cancelled.');
            }

            $this->archiveOrganization->handle($organization);
        });
    }
}
