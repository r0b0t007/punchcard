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
            $this->point($model, $column, $path);
        } catch (Throwable $failed) {
            $disk->delete($path);

            throw $failed;
        }

        return $path;
    }

    /** The row points at no file any more (a cover removed, B17); the old file goes once that is committed. */
    public function clear(Model $model, string $column): void
    {
        $this->point($model, $column, null);
    }

    /** Points the locked row at $path, then deletes the file it pointed at once the change is committed. */
    private function point(Model $model, string $column, ?string $path): void
    {
        $old = DB::transaction(function () use ($model, $column, $path): mixed {
            $locked = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            $old = $locked->getAttribute($column);
            $locked->forceFill([$column => $path])->save();
            $model->forceFill([$column => $path])->syncOriginal();

            return $old;
        });

        if (is_string($old) && $old !== $path) {
            DB::afterCommit(fn () => Storage::disk('public')->delete($old));
        }
    }
}
