<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RewardStatus;
use App\Enums\RewardType;
use App\Models\Concerns\GuardsTenantWrites;
use App\Models\Concerns\HoldsCustomerData;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantModel;
use Database\Factories\RewardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A reward a customer unlocked: one per milestone of an enrollment, with a
 * copy of what was earned. Redemption records who, when, and at which
 * business and location (ADR 0006 attribution). Customer data of the
 * organization (HoldsCustomerData).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $enrollment_id
 * @property int $milestone
 * @property RewardType $reward_type
 * @property int|null $reward_value
 * @property string $reward_text
 * @property RewardStatus $status
 * @property Carbon $unlocked_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $redeemed_at
 * @property int|null $redeemed_by
 * @property int|null $redeemed_business_id
 * @property int|null $redeemed_location_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'organization_id', 'enrollment_id', 'milestone', 'reward_type', 'reward_value', 'reward_text', 'status',
    'unlocked_at', 'expires_at', 'redeemed_at', 'redeemed_by', 'redeemed_business_id', 'redeemed_location_id',
])]
#[UseEloquentBuilder(TenantBuilder::class)]
class Reward extends Model implements TenantModel
{
    use GuardsTenantWrites;

    /** @use HasFactory<RewardFactory> */
    use HasFactory;

    use HoldsCustomerData;

    /** organization_id is the enrollment's, also in bypass(). */
    public function fillTenantColumns(): void
    {
        $this->fillOrganizationFrom(CardEnrollment::class, 'enrollment_id');
    }

    /**
     * @return BelongsTo<CardEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CardEnrollment::class, 'enrollment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by');
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function redeemedBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'redeemed_business_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function redeemedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'redeemed_location_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'milestone' => 'integer',
            'reward_type' => RewardType::class,
            'reward_value' => 'integer',
            'status' => RewardStatus::class,
            'unlocked_at' => 'datetime',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }

    /** A reward stays what was earned, by whom, at which milestone. */
    protected function immutableColumns(): array
    {
        return ['enrollment_id', 'milestone', 'reward_type', 'reward_value', 'reward_text', 'unlocked_at'];
    }
}
