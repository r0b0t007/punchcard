<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Actions\Branding\UpdateLogo;
use App\Enums\OnboardingStep;
use App\Models\Business;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use stdClass;
use Throwable;

/**
 * The wizard's logo step (CHW-31): the organization's logo (shown on the
 * card, the QR stand and the Wallet pass), or no logo for now: the step can
 * be skipped and the logo added later in the brand settings. If the step
 * fails after the logo was stored, the stored file is deleted: nothing
 * points at it.
 */
final readonly class SaveLogoStep
{
    public function __construct(
        private UpdateLogo $updateLogo,
        private CompleteStep $completeStep,
    ) {}

    public function handle(Business $business, ?UploadedFile $logo): void
    {
        $stored = new stdClass;
        $stored->path = null;

        try {
            DB::transaction(function () use ($business, $logo, $stored): void {
                if ($logo instanceof UploadedFile) {
                    $stored->path = $this->updateLogo->handle($business->organization, $logo);
                }

                $this->completeStep->handle($business, OnboardingStep::Logo);
            });
        } catch (Throwable $failed) {
            if (is_string($stored->path)) {
                Storage::disk('public')->delete($stored->path);
            }

            throw $failed;
        }
    }
}
