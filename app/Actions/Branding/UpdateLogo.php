<?php

declare(strict_types=1);

namespace App\Actions\Branding;

use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The organization's logo (CHW-31; brand settings, CHW-135, reuse it). The
 * file goes on the public disk under a random name, never the uploaded one.
 * The organization's row is locked while the logo is swapped, so of two
 * uploads at once each replaces the other's file: the old file is deleted
 * once the change is committed, the new one if this change fails (a caller
 * rolling back its own transaction afterwards deletes it too, SaveLogoStep).
 * Validation (type, size, dimensions) is the caller's. Runs in the tenant:
 * the organization's guard decides who may change it.
 */
final readonly class UpdateLogo
{
    public function handle(Organization $organization, UploadedFile $file): string
    {
        $disk = Storage::disk('public');
        $path = $disk->putFile("logos/{$organization->id}", $file);

        if (! is_string($path)) {
            throw new RuntimeException('The logo could not be stored.');
        }

        try {
            $old = DB::transaction(function () use ($organization, $path): ?string {
                $locked = Organization::query()->lockForUpdate()->findOrFail($organization->id);
                $old = $locked->logo_path;
                $locked->forceFill(['logo_path' => $path])->save();
                $organization->forceFill(['logo_path' => $path])->syncOriginal();

                return $old;
            });
        } catch (Throwable $failed) {
            $disk->delete($path);

            throw $failed;
        }

        if ($old !== null && $old !== $path) {
            DB::afterCommit(fn () => $disk->delete($old));
        }

        return $path;
    }
}
