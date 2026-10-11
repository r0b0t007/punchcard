<?php

declare(strict_types=1);

namespace App\Actions\Branding;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * An uploaded image a row points at (an organization's logo, a card's
 * banner): the file goes on the public disk under a random name, never the
 * uploaded one. The row is locked while the path is swapped, so of two
 * uploads at once each replaces the other's file: the old file is deleted
 * once the change is committed, the new one if this change fails (a caller
 * rolling back its own transaction afterwards deletes it too, SaveLogoStep).
 * Validation (type, size, dimensions) is the caller's. Runs in the tenant:
 * the model's guard decides who may change it.
 */
final readonly class SwapPublicFile
{
    public function handle(Model $model, string $column, string $directory, UploadedFile $file): string
    {
        $disk = Storage::disk('public');
        $path = $disk->putFile($directory, $file);

        if (! is_string($path)) {
            throw new RuntimeException('The file could not be stored.');
        }

        try {
            $old = DB::transaction(function () use ($model, $column, $path): mixed {
                $locked = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
                $old = $locked->getAttribute($column);
                $locked->forceFill([$column => $path])->save();
                $model->forceFill([$column => $path])->syncOriginal();

                return $old;
            });
        } catch (Throwable $failed) {
            $disk->delete($path);

            throw $failed;
        }

        if (is_string($old) && $old !== $path) {
            DB::afterCommit(fn () => $disk->delete($old));
        }

        return $path;
    }
}
