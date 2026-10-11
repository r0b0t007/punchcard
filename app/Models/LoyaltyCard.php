<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CardMode;
use App\Enums\RewardType;
use App\Models\Concerns\ChangedOnlyByOrgAdmin;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Branding\Contrast;
use App\Support\Cards\ProgressiveTiers;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Database\Factories\LoyaltyCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property array{brand?: string, foreground?: string}|null $colors the brand colour and the text colour stored for it (Contrast::readableForeground, CHW-32)
 * @property array{hours?: string, phone?: string, links?: list<array{label: string, url: string}>}|null $info what customers read with the card (CHW-32)
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
    'reward_value', 'reward_text', 'colors', 'icon', 'terms', 'info', 'cooldown_min', 'daily_cap', 'active',
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
     * A card's mode and tiers are fixed once customers hold it, also in
     * bypass(): progressive enrollments never reset, so turning the card cyclic
     * would pay their stamps out as rewards at the next tap, and moving or
     * adding a tier would skip a reward or pay one twice. Bulk updates change
     * neither.
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        $this->assertProgramWrite($operation, $values);
        $this->heldUnderLock = null;
        $this->assertStampsNotRaisedWhileHeld($values);

        if (array_key_exists('mode', $values) || array_key_exists('tiers', $values)) {
            if (! $this->exists || $this->heldUnderLock()) {
                throw new LogicException('A card\'s mode and tiers cannot change once customers hold it (or in a bulk update): start a new card.');
            }

            // Only a single, loaded card gets here: the stored mode and tiers fill in what this write leaves out.
            $this->assertTiers(
                $values['mode'] ?? $this->getRawOriginal('mode'),
                array_key_exists('tiers', $values) ? $values['tiers'] : $this->getRawOriginal('tiers'),
            );
        }
    }

    /**
     * A card's stamp count may be lowered at any time: nothing is taken from
     * anyone, and a customer who now has enough is paid with their next stamp
     * (AddStamps). Raising it once customers hold the card would move their
     * goal, so it is refused, also in bypass(): start a new card instead
     * (CHW-32).
     *
     * Only a loaded card's save changes it: the value written is the one set
     * on that card. A bulk write can't tell which cards are held; increment()
     * writes a delta; a query built from a loaded card writes other cards.
     * The new count is compared with the stored one under a row lock, so a
     * stale copy of the card can't raise it, and a racing first enrollment
     * commits before the check or waits for the change.
     *
     * @param  array<string, mixed>  $values
     */
    private function assertStampsNotRaisedWhileHeld(array $values): void
    {
        if (! array_key_exists('stamps_required', $values)) {
            return;
        }

        $saving = $this->isOwnTenantWrite() && $this->isDirty('stamps_required')
            && (int) $this->getAttributes()['stamps_required'] === (int) $values['stamps_required'];

        if (! $saving) {
            throw new LogicException('A card\'s stamp count changes only by saving that one card (not in bulk, by increment() or by a query built from a card), so whether customers hold it is checked.');
        }

        $stored = app(TenantContext::class)->bypass(fn (): mixed => self::query()->whereKey($this->id)->lockForUpdate()->value('stamps_required'));

        if ((int) $values['stamps_required'] <= (int) $stored) {
            return;
        }

        if ($this->heldUnderLock()) {
            throw new LogicException('A card\'s stamp count can be lowered, never raised, once customers hold it: start a new card.');
        }
    }

    /** Whether customers hold the card, read once per write (assertTenantWrite clears it). */
    private ?bool $heldUnderLock = null;

    /**
     * Whether customers hold this card, the row locked first: a racing first
     * enrollment (its foreign key locks the card too) commits before the
     * check, or waits for the change. Once per write, however many rules ask.
     */
    private function heldUnderLock(): bool
    {
        return $this->heldUnderLock ??= app(TenantContext::class)->bypass(fn (): bool => self::query()->whereKey($this->id)->lockForUpdate()->exists()
            && self::query()->whereKey($this->id)->held()->exists());
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
     * Cards customers hold: their mode and tiers are fixed, and they are
     * switched off, never deleted. Use it in bypass(), where no tenant scope
     * hides another business's enrollments.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function held(Builder $query): void
    {
        $query->whereHas('enrollments');
    }

    /**
     * The cards a business honours, in the order a first tap there picks one
     * (CHW-25): an active card first, then the oldest.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function honouredBy(Builder $query, int $businessId): void
    {
        $query->whereHas('businesses', fn (Builder $businesses) => $businesses->whereKey($businessId))
            ->orderByDesc('active')
            ->orderBy('id');
    }

    /** The card's own brand colour (CHW-32), or null: it then shows its organization's (ADR 0007). */
    public function brandColor(): ?string
    {
        $brand = $this->colors['brand'] ?? null;

        return is_string($brand) && Contrast::isHex($brand) ? $brand : null;
    }

    /** The text colour stored for the card's own brand colour (SaveCard), or null with no colour of its own. */
    public function brandForeground(): ?string
    {
        $foreground = $this->colors['foreground'] ?? null;

        return $this->brandColor() !== null && is_string($foreground) && Contrast::isHex($foreground) ? $foreground : null;
    }

    /**
     * The customers holding the card.
     *
     * @return HasMany<CardEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(CardEnrollment::class, 'card_id');
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
     * The businesses honouring the card that still operate
     * (Business::operating), by name: the cards page lists them (CHW-32).
     *
     * @return BelongsToMany<Business, $this, CardBusiness>
     */
    public function operatingBusinesses(): BelongsToMany
    {
        return $this->businesses()->operating()->orderBy('name');
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
            'info' => 'array',
            'cooldown_min' => 'integer',
            'daily_cap' => 'integer',
            'active' => 'boolean',
        ];
    }
}
