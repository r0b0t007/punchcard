<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CardMode;
use App\Enums\RewardType;
use App\Models\Concerns\ChangedOnlyByOrgAdmin;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Cards\ProgressiveTiers;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Database\Factories\LoyaltyCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use LogicException;

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
 * @property array<array-key, mixed>|null $tiers progressive tiers, meant as list<array{stamps: int, reward: string}>; unvalidated JSON, which AddStamps checks when it reads them
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
        assertTenantWrite as assertProgramWrite;
    }
    use GuardsTenantWrites;

    /** @use HasFactory<LoyaltyCardFactory> */
    use HasFactory;

    /**
     * A card's mode is fixed once customers hold it, also in bypass():
     * progressive enrollments never reset, so turning the card cyclic would pay
     * their stamps out as rewards at the next tap. Bulk updates change no mode.
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        $this->assertProgramWrite($operation, $values);

        if (array_key_exists('mode', $values)) {
            $held = ! $this->exists || app(TenantContext::class)->bypass(fn (): bool => CardEnrollment::query()->where('card_id', $this->id)->exists());

            if ($held) {
                throw new LogicException('A card\'s mode cannot change once customers hold it (or in a bulk update): start a new card.');
            }
        }

        if (array_key_exists('tiers', $values) && ! $this->exists) {
            throw new LogicException('A card\'s tiers change one card at a time, where its mode is known: not in a bulk update.');
        }

        if (array_key_exists('mode', $values) || array_key_exists('tiers', $values)) {
            $this->assertTiers(
                $values['mode'] ?? ($this->exists ? $this->getRawOriginal('mode') : null),
                array_key_exists('tiers', $values) ? $values['tiers'] : ($this->exists ? $this->getRawOriginal('tiers') : null),
            );
        }
    }

    /**
     * A progressive card's tiers are checked when it is saved, also in
     * bypass(), so one bad edit cannot stop every stamp on the card. A cyclic
     * card ignores its tiers.
     */
    private function assertTiers(mixed $mode, mixed $tiers): void
    {
        if ($mode === CardMode::Progressive || $mode === CardMode::Progressive->value) {
            ProgressiveTiers::parse($tiers);
        }
    }

    /**
     * No new card in an archived organization, also in bypass().
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertProgramInsert($values);
        $this->assertTiers($values['mode'] ?? null, $values['tiers'] ?? null);
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
