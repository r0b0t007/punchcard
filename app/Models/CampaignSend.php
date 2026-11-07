<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\FillsOnce;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Carbon\CarbonInterface;
use Database\Factories\CampaignSendFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A campaign to one follower (CHW-48, B10): site data, a franchisee's own.
 * Claimed before the push (SendCampaignBatch), once per campaign and
 * follower, and kept: the campaign's results read it. Only sent_at (a
 * phone's push service accepted it) and opened_at (their first tap on it)
 * change, once each (a trigger holds that on both drivers), through
 * markSent() and markOpened(): neither is fillable, so an update() would
 * drop them. Deleted only in bypass(): with the customer's account
 * (DeleteAccount) or the business.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property int $campaign_id
 * @property int $user_id the follower it went to
 * @property Carbon|null $sent_at
 * @property Carbon|null $opened_at
 * @property Carbon $created_at
 */
#[Fillable(['business_id', 'campaign_id', 'user_id'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class CampaignSend extends Model implements TenantModel
{
    use BelongsToBusiness;
    use FillsOnce;
    use GuardsTenantWrites;

    /** @use HasFactory<CampaignSendFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    /**
     * Kept as claimed: only when it was sent and first opened are filled in,
     * once each (the trigger says when); deleted only in bypass(), with the
     * customer.
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if ($operation === 'delete' ? ! app(TenantContext::class)->isBypassed() : array_diff(array_keys($values), ['sent_at', 'opened_at']) !== []) {
            throw new LogicException('A campaign send is kept as claimed: only its sent_at and opened_at are filled in.');
        }
    }

    /** Fills in when a phone's push service accepted it, once: false when it already was (a retry, another worker). */
    public function markSent(CarbonInterface $at): bool
    {
        return $this->fillOnce('sent_at', $at);
    }

    /** Fills in when they first tapped it, once: false when they already had. */
    public function markOpened(CarbonInterface $at): bool
    {
        return $this->fillOnce('opened_at', $at);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Those a phone's push service accepted it for: the recipients B10 counts, never a claim still pushing.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function reached(Builder $query): void
    {
        $query->whereNotNull('sent_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }
}
