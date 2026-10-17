<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Database\Factories\CounterScanFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A staff member's scan of a customer's member QR, or its short code
 * (CHW-30): made only by ScanMemberQr from a checked code, it is that staff
 * member's for SECONDS at one location, stamps once (StampScan) and proves
 * the customer is at the counter to redeem (RedeemScan). Site data: a
 * franchisee sees its own. It names a customer: rows go a day after they ran
 * out.
 *
 * @property string $id
 * @property int $organization_id
 * @property int $business_id
 * @property int $location_id
 * @property int $user_id the customer scanned
 * @property int $staff_id who scanned
 * @property int $member_qr_window the window of the code scanned (MemberQrToken)
 * @property Carbon $scanned_at
 * @property Carbon $expires_at
 * @property int|null $stamp_event_id the stamp it gave (StampScan)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseEloquentBuilder(TenantBuilder::class)]
class CounterScan extends Model implements TenantModel
{
    use BelongsToBusiness;
    use GuardsTenantWrites;

    /** @use HasFactory<CounterScanFactory> */
    use HasFactory;

    use HasUlids;
    use MassPrunable {
        pruneAll as private pruneAllRows;
    }

    /** How long staff have, after scanning, to stamp or redeem with it. */
    public const int SECONDS = 120;

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }

    /** The scheduled model:prune runs outside a tenant: every business's old scans, in bypass(). */
    public function pruneAll(int $chunkSize = 1000): int
    {
        return app(TenantContext::class)->bypass(fn (): int => $this->pruneAllRows($chunkSize));
    }

    public function hasExpired(): bool
    {
        return ! $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'member_qr_window' => 'integer',
            'scanned_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
