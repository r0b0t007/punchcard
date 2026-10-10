<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Models\LoyaltyCard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * A card's banner (CHW-32, B6), as UpdateLogo stores a logo: on the public
 * disk under a random name, never the uploaded one. The card's row is
 * locked while the banner is swapped: the old file is deleted once the
 * change is committed, the new one if the change fails. Validation (type,
 * size, dimensions) is the caller's. Runs in the tenant, as its org admin:
 * LoyaltyCard's guard decides.
 */
final readonly class UpdateCardBanner
{
    public function handle(LoyaltyCard $card, UploadedFile $file): string
    {
        $disk = Storage::disk('public');
        $path = $disk->putFile("banners/{$card->organization_id}", $file);

        if (! is_string($path)) {
            throw new RuntimeException('The banner could not be stored.');
        }

        try {
            $old = DB::transaction(function () use ($card, $path): ?string {
                $locked = LoyaltyCard::query()->lockForUpdate()->findOrFail($card->id);
                $old = $locked->banner_path;
                $locked->forceFill(['banner_path' => $path])->save();
                $card->forceFill(['banner_path' => $path])->syncOriginal();

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
