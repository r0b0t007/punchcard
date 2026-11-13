<?php

declare(strict_types=1);

namespace App\Actions\Branding;

use App\Models\Organization;
use Illuminate\Http\UploadedFile;

/**
 * The organization's cover (CHW-135, B17), a wide photo at the top of the tap
 * screens and the card page, under covers/{organization} (SwapPublicFile).
 * Runs in the tenant: the organization's guard decides who may change it.
 */
final readonly class UpdateCover
{
    public function __construct(private SwapPublicFile $swapPublicFile) {}

    public function handle(Organization $organization, UploadedFile $file): string
    {
        return $this->swapPublicFile->handle($organization, 'cover_path', "covers/{$organization->id}", $file);
    }
}
