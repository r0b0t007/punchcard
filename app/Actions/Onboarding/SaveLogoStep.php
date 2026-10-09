<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Branding\UpdateLogo;
use App\Enums\OnboardingStep;
use App\Models\Business;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The wizard's logo step (CHW-31): the organization's logo (shown on the
 * card, the QR stand and the Wallet pass), or no logo for now: the step can
 * be skipped and the logo added later in the brand settings.
 */
final readonly class SaveLogoStep
{
    public function __construct(
        private UpdateLogo $updateLogo,
        private CompleteStep $completeStep,
    ) {}

    public function handle(Business $business, ?UploadedFile $logo): void
    {
        DB::transaction(function () use ($business, $logo): void {
            if ($logo instanceof UploadedFile) {
                $this->updateLogo->handle($business->organization, $logo);
            }

            $this->completeStep->handle($business, OnboardingStep::Logo);
        });
    }
}
