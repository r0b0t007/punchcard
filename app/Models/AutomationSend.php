<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationTrigger;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Carbon\CarbonInterface;
use Database\Factories\AutomationSendFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An automation message to one customer (CHW-46): site data, a franchisee's
 * own. Claimed before it is sent, once per occasion (a quiet spell, a
 * birthday's year, a reward), and kept: the weekly cap and the B11 counts
 * read it. Only sent_at changes, once, when a phone's push service accepted
 * it (a trigger holds that on both drivers), through markSent(): sent_at is
 * not fillable, so an update() would drop it. Deleted only in bypass():
 * with the customer's account (DeleteAccount) or the business, or let go
 * when never sent (RunAutomations, AutomationWindow::RELEASE_MINUTES).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property int $automation_id
 * @property int $user_id the customer it went to
 * @property AutomationTrigger $trigger
 * @property string $occasion what it was sent for, once: "quiet:2026-11-04", "birthday:2026", "reward:12"
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 */
#[Fillable(['business_id', 'automation_id', 'user_id', 'trigger', 'occasion'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class AutomationSend extends Model implements TenantModel
{
    use BelongsToBusiness;
    use GuardsTenantWrites;

    /** @use HasFactory<AutomationSendFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    /**
     * Kept as claimed: only the moment it was sent is filled in, once (the
     * trigger says when); deleted only in bypass(): with the customer, or
     * let go when never sent.
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if ($operation === 'delete' ? ! app(TenantContext::class)->isBypassed() : array_diff(array_keys($values), ['sent_at']) !== []) {
            throw new LogicException('An automation send is kept as claimed: only its sent_at is filled in.');
        }
    }

    /**
     * Fills in when a phone's push service accepted it, once: false when it
     * already was (a retry, another worker).
     */
    public function markSent(CarbonInterface $at): bool
    {
        if (static::query()->whereKey($this->id)->whereNull('sent_at')->update(['sent_at' => $at]) !== 1) {
            return false;
        }

        $this->forceFill(['sent_at' => $at])->syncOriginalAttribute('sent_at');

        return true;
    }

    /**
     * @return BelongsTo<Automation, $this>
     */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => AutomationTrigger::class,
            'sent_at' => 'datetime',
        ];
    }
}
