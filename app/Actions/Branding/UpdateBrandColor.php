<?php

declare(strict_types=1);

namespace App\Actions\Branding;

use App\Models\Organization;

/**
 * The organization's brand colour (CHW-135, B17): #RRGGBB upper-cased, or
 * null for punchcard's espresso. It paints the cards that have no colour of
 * their own, a franchise's every card, the tap screens and the Wallet pass
 * (ADR 0007); the text on it is worked out when read (Organization::
 * brandForeground). Runs in the tenant: the organization's guard decides.
 */
final readonly class UpdateBrandColor
{
    public function handle(Organization $organization, ?string $color): void
    {
        $organization->update(['brand_color' => $color === null ? null : strtoupper($color)]);
    }
}
