<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Actions\Branding\SwapPublicFile;
use App\Models\LoyaltyCard;
use Illuminate\Http\UploadedFile;

/**
 * A card's banner (CHW-32, B6), under banners/{organization}
 * (SwapPublicFile). Runs in the tenant, as its org admin: LoyaltyCard's
 * guard decides.
 */
final readonly class UpdateCardBanner
{
    public function __construct(private SwapPublicFile $swapPublicFile) {}

    public function handle(LoyaltyCard $card, UploadedFile $file): string
    {
        return $this->swapPublicFile->handle($card, 'banner_path', "banners/{$card->organization_id}", $file);
    }
}
