<?php

declare(strict_types=1);

namespace App\Actions\Branding;

use App\Models\Organization;
use Illuminate\Http\UploadedFile;

/**
 * The organization's logo (CHW-31; brand settings, CHW-135, reuse it), under
 * logos/{organization} (SwapPublicFile). Runs in the tenant: the
 * organization's guard decides who may change it.
 */
final readonly class UpdateLogo
{
    public function __construct(private SwapPublicFile $swapPublicFile) {}

    public function handle(Organization $organization, UploadedFile $file): string
    {
        return $this->swapPublicFile->handle($organization, 'logo_path', "logos/{$organization->id}", $file);
    }
}
