<?php

declare(strict_types=1);

namespace App\Actions\Branding;

use App\Models\Organization;

/**
 * Takes the organization's cover away (CHW-135, B17): the row points at none,
 * and the file goes once that is committed (SwapPublicFile::clear). Runs in
 * the tenant: the organization's guard decides.
 */
final readonly class RemoveCover
{
    public function __construct(private SwapPublicFile $swapPublicFile) {}

    public function handle(Organization $organization): void
    {
        $this->swapPublicFile->clear($organization, 'cover_path');
    }
}
