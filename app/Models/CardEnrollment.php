<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StampSource;
use App\Models\Concerns\GuardsTenantWrites;
use App\Models\Concerns\HoldsCustomerData;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use App\Support\Tenancy\VisibleToBusiness;
use Database\Factories\CardEnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A customer's copy of a loyalty card, shared by every business that honours
 * it. Customer data of the organization (HoldsCustomerData): the org admin
 * sees every member; a franchisee sees the members who stamped there. The
 * counts are a cache of stamp_events: only the stamp Actions set them, and the enrollment Action
 * the referral code and referrer, with forceFill(), so a request can never
 * mass-assign them. The referrer never changes once set.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $card_id
 * @property int $user_id
 * @property string|null $referral_code
 * @property int|null $referred_by
 * @property int $current_stamps
 * @property int $lifetime_stamps
 * @property int $completed_count
 * @property Carbon|null $last_stamp_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'card_id', 'user_id'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class CardEnrollment extends Model implements TenantModel, VisibleToBusiness
{
    use GuardsTenantWrites;

    /** @use HasFactory<CardEnrollmentFactory> */
    use HasFactory;

    use HoldsCustomerData {
        assertTenantInsert as assertCustomerDataInsert;
        assertTenantWrite as assertCustomerDataWrite;
    }

    /** Progress, a cache of the stamp ledger: only the stamp Actions change it, in bypass(). */
    private const array LEDGER_COLUMNS = ['current_stamps', 'lifetime_stamps', 'completed_count', 'last_stamp_at'];

    /** Columns only the stamp and enrollment Actions change, in bypass(). */
    private const array ACTION_COLUMNS = [...self::LEDGER_COLUMNS, 'referral_code'];

    /**
     * Outside bypass() a new enrollment starts with no progress: stamps come
     * with ledger entries, from the stamp Actions.
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertCustomerDataInsert($values);
        ArchivedSites::assertOrganizationOpen($values['organization_id'] ?? null, 'An enrollment', lock: true);

        if (app(TenantContext::class)->isBypassed()) {
            return;
        }

        $progress = array_filter(
            array_intersect_key($values, array_flip(self::LEDGER_COLUMNS)),
            fn (mixed $value): bool => ! in_array($value, [null, 0, '0'], true),
        );

        if ($progress !== []) {
            throw new LogicException('A new enrollment starts with no progress: the stamp Actions add stamps, in TenantContext::bypass().');
        }
    }

    /**
     * Progress changes only with the ledger, so a counter never moves without
     * a stamp event behind it (a franchisee setting it would mint rewards).
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        $this->assertCustomerDataWrite($operation, $values);

        if (! app(TenantContext::class)->isBypassed() && array_intersect(array_keys($values), self::ACTION_COLUMNS) !== []) {
            throw new LogicException('Progress is a cache of the stamp ledger, and the referral code the enrollment Action\'s: they change in TenantContext::bypass().');
        }
    }

    /** organization_id is the card's, also in bypass(). */
    public function fillTenantColumns(): void
    {
        $this->fillOrganizationFrom(LoyaltyCard::class, 'card_id');
    }

    /**
     * @return BelongsTo<LoyaltyCard, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCard::class, 'card_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CardEnrollment, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by');
    }

    /**
     * A franchisee sees the members who were there: a stamp proving presence
     * (StampSource::presenceValues()) at this business (ADR 0006). A bonus,
     * birthday or referral stamp recorded at a business does not count.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainToBusiness(Builder $query, int $businessId): void
    {
        $query->whereHas('stampEvents', fn (Builder $events) => $events
            ->where('business_id', $businessId)
            ->whereIn('source', StampSource::presenceValues()));
    }

    /**
     * @return HasMany<StampEvent, $this>
     */
    public function stampEvents(): HasMany
    {
        return $this->hasMany(StampEvent::class, 'enrollment_id');
    }

    /**
     * @return HasMany<Reward, $this>
     */
    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class, 'enrollment_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_stamps' => 'integer',
            'lifetime_stamps' => 'integer',
            'completed_count' => 'integer',
            'last_stamp_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function cardIdFor(array $values): mixed
    {
        return $values['card_id'] ?? null;
    }

    /** An enrollment stays the same customer's copy of the same card, referred by the same member. */
    protected function immutableColumns(): array
    {
        return ['card_id', 'user_id', 'referred_by'];
    }
}
