<?php

declare(strict_types=1);

namespace App\Actions\Branding;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Takes the organization's cover away (CHW-135, B17). The row is locked while
 * the path is cleared, as SwapPublicFile does, and the file is deleted once
 * that is committed. Runs in the tenant: the organization's guard decides.
 */
final readonly class RemoveCover
{
    public function handle(Organization $organization): void
    {
        $old = DB::transaction(function () use ($organization): mixed {
            $locked = $organization->newQuery()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $old = $locked->cover_path;
            $locked->forceFill(['cover_path' => null])->save();
            $organization->forceFill(['cover_path' => null])->syncOriginal();

            return $old;
        });

        if (is_string($old)) {
            DB::afterCommit(fn () => Storage::disk('public')->delete($old));
        }
    }
}
