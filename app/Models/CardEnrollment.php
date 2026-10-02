<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardsTenantWrites;
use App\Models\Concerns\HoldsCustomerData;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantModel;
use Database\Factories\CardEnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer's copy of a loyalty card, shared by every business that honours
 * it. Customer data of the organization (HoldsCustomerData): the org admin
 * sees every member; a business sees none until PR C. The counts are a cache
 * of stamp_events.
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
#[Fillable([
    'organization_id', 'card_id', 'user_id', 'referral_code', 'referred_by',
    'current_stamps', 'lifetime_stamps', 'completed_count', 'last_stamp_at',
])]
#[UseEloquentBuilder(TenantBuilder::class)]
class CardEnrollment extends Model implements TenantModel
{
    use GuardsTenantWrites;

    /** @use HasFactory<CardEnrollmentFactory> */
    use HasFactory;

    use HoldsCustomerData;

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

    /** An enrollment stays the same customer's copy of the same card. */
    protected function immutableColumns(): array
    {
        return ['card_id', 'user_id'];
    }
}
