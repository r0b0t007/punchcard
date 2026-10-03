<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CardMode;
use App\Enums\RewardType;
use App\Models\Concerns\ChangedOnlyByOrgAdmin;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantModel;
use Database\Factories\LoyaltyCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A loyalty card: the organization's program (ADR 0006), honoured by the
 * businesses in card_business. Every business of the organization sees it;
 * only an org admin changes it.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property int $stamps_required
 * @property CardMode $mode
 * @property list<array{stamps: int, reward: string}>|null $tiers
 * @property string|null $stamp_style
 * @property string|null $banner_path
 * @property RewardType $reward_type
 * @property int|null $reward_value
 * @property string $reward_text
 * @property array<string, string>|null $colors
 * @property string|null $icon
 * @property string|null $terms
 * @property int $cooldown_min
 * @property int|null $daily_cap
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'organization_id', 'name', 'stamps_required', 'mode', 'tiers', 'stamp_style', 'banner_path', 'reward_type',
    'reward_value', 'reward_text', 'colors', 'icon', 'terms', 'cooldown_min', 'daily_cap', 'active',
])]
#[UseEloquentBuilder(TenantBuilder::class)]
class LoyaltyCard extends Model implements TenantModel
{
    use ChangedOnlyByOrgAdmin {
        assertTenantInsert as assertProgramInsert;
    }
    use GuardsTenantWrites;

    /** @use HasFactory<LoyaltyCardFactory> */
    use HasFactory;

    /**
     * No new card in an archived organization, also in bypass().
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertProgramInsert($values);
        ArchivedSites::assertOrganizationOpen($values['organization_id'] ?? null, 'A card', lock: true);
    }

    /**
     * The businesses that honour the card. Uses the guarded CardBusiness pivot,
     * so attach(), detach(), sync() and toggle() go through model saves. Never
     * use newPivotQuery() or newPivotStatement(): they skip the guards.
     *
     * @return BelongsToMany<Business, $this, CardBusiness>
     */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'card_business', 'card_id')
            ->using(CardBusiness::class)
            ->withPivot('id', 'organization_id')
            ->withTimestamps();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stamps_required' => 'integer',
            'mode' => CardMode::class,
            'tiers' => 'array',
            'reward_type' => RewardType::class,
            'reward_value' => 'integer',
            'colors' => 'array',
            'cooldown_min' => 'integer',
            'daily_cap' => 'integer',
            'active' => 'boolean',
        ];
    }
}
