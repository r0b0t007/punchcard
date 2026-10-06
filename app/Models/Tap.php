<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\Concerns\IsPlatformData;
use App\Support\Tenancy\PlatformBuilder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One tap on /t (CHW-25): verified and pending, stamped, or refused with its
 * reason. Platform data, like the tags it records (IsPlatformData): the tap
 * Actions write it in bypass(); the owner's fraud view (CHW-36) will read a
 * business's own rows. ip and user_agent are personal data: rows are pruned
 * after punchcard.taps.retention_days, and DeleteAccount scrubs a user's.
 *
 * @property int $id
 * @property int|null $nfc_tag_id
 * @property int|null $stamper_id
 * @property int|null $business_id
 * @property int|null $location_id
 * @property int|null $card_id
 * @property int|null $card_stamps
 * @property int|null $counter
 * @property int|null $user_id
 * @property string|null $claim_token_hash
 * @property int $qty
 * @property bool $armed it took stamps staff had armed (ReceiveTap)
 * @property TapStatus $status
 * @property TapRejection|null $rejection
 * @property int|null $stamp_event_id
 * @property int|null $reward_id the reward it redeemed instead of stamping (CHW-26)
 * @property Carbon|null $available_at
 * @property Carbon|null $expires_at
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseEloquentBuilder(PlatformBuilder::class)]
class Tap extends Model
{
    use IsPlatformData;
    use MassPrunable {
        pruneAll as private pruneAllRows;
    }

    /** @var array<string, mixed> The database defaults, also in memory before a refresh. */
    protected $attributes = ['qty' => 1, 'armed' => false];

    /**
     * Rows older than the retention period, personal data included.
     *
     * @return PlatformBuilder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('punchcard.taps.retention_days')));
    }

    /** The scheduled model:prune runs outside a tenant: the tap log is platform data, deleted in bypass(). */
    public function pruneAll(int $chunkSize = 1000): int
    {
        return app(TenantContext::class)->bypass(fn (): int => $this->pruneAllRows($chunkSize));
    }

    /**
     * The taps waiting under a session's claim token (TapSession, CHW-142):
     * pending or expired ones whose expiry is less than a day old, so the claim
     * can say they expired. Older expired ones stay out: a browser that
     * re-sends an expired session id re-mints the same token, and must not
     * get an earlier visitor's taps back.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function waitingUnder(Builder $query, string $claimTokenHash): void
    {
        // Bounded for pending taps too: the scheduler may be late in expiring them.
        $query->where('claim_token_hash', $claimTokenHash)
            ->whereIn('status', [TapStatus::Pending, TapStatus::Expired])
            ->where('expires_at', '>', now()->subDay());
    }

    public function isPending(): bool
    {
        return $this->status === TapStatus::Pending;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'counter' => 'integer',
            'qty' => 'integer',
            'armed' => 'boolean',
            'card_stamps' => 'integer',
            'status' => TapStatus::class,
            'rejection' => TapRejection::class,
            'available_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
