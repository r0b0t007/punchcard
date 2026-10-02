<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CardMode;
use App\Enums\RewardStatus;
use App\Enums\RewardType;
use App\Models\Concerns\GuardsTenantWrites;
use App\Models\Concerns\HoldsCustomerData;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Database\Factories\RewardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A reward a customer unlocked: one per milestone of an enrollment (the
 * completion number of a cyclic card, the tier threshold of a progressive
 * one, told apart by mode), with a copy of what was earned. It is unlocked
 * available; redemption, an update by the redeem Action with forceFill(),
 * records who, when, and at which business and location (ADR 0006
 * attribution). Customer data of the organization (HoldsCustomerData).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $enrollment_id
 * @property CardMode $mode
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
    'organization_id', 'enrollment_id', 'mode', 'milestone', 'reward_type', 'reward_value', 'reward_text',
    'unlocked_at', 'expires_at',
])]
#[UseEloquentBuilder(TenantBuilder::class)]
class Reward extends Model implements TenantModel
{
    use GuardsTenantWrites;

    /** @use HasFactory<RewardFactory> */
    use HasFactory;

    use HoldsCustomerData {
        assertTenantInsert as assertCustomerDataInsert;
    }

    /** Redemption fields a new reward cannot have outside bypass(). */
    private const array REDEMPTION_COLUMNS = ['redeemed_at', 'redeemed_by', 'redeemed_business_id', 'redeemed_location_id'];

    /**
     * A reward is unlocked available: creating one already redeemed, or
     * credited to some business, would fake the franchise's "redeemed here"
     * report. Imports and corrections use bypass().
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertCustomerDataInsert($values);

        if (app(TenantContext::class)->isBypassed()) {
            return;
        }

        $status = $values['status'] ?? RewardStatus::Available;
        $redeemed = array_filter(array_intersect_key($values, array_flip(self::REDEMPTION_COLUMNS)), fn (mixed $value): bool => $value !== null);

        if (($status instanceof RewardStatus ? $status : RewardStatus::tryFrom((string) $status)) !== RewardStatus::Available || $redeemed !== []) {
            throw new LogicException('A reward is unlocked available; redeeming it is an update by the redeem Action.');
        }
    }

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
            'mode' => CardMode::class,
            'milestone' => 'integer',
            'reward_type' => RewardType::class,
            'reward_value' => 'integer',
            'status' => RewardStatus::class,
            'unlocked_at' => 'datetime',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function cardIdFor(array $values): mixed
    {
        return app(TenantContext::class)->bypass(
            fn (): mixed => CardEnrollment::query()->whereKey($values['enrollment_id'] ?? null)->value('card_id'),
        );
    }

    /** A reward stays what was earned, by whom, at which milestone. */
    protected function immutableColumns(): array
    {
        return ['enrollment_id', 'mode', 'milestone', 'reward_type', 'reward_value', 'reward_text', 'unlocked_at'];
    }
}
