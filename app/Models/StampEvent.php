<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StampSource;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Database\Factories\StampEventFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A stamp event: one line of the append-only ledger every count derives from
 * (stamp-flow skill). Site data, so a franchisee sees its own events and the
 * org admin the organization's. It is only ever inserted by the stamp Actions,
 * after proof of presence, in TenantContext::bypass() (with forceFill():
 * nothing is mass-assignable), at a business that honours the enrollment's
 * card. That last rule is checked here, for model inserts; the database
 * still keeps raw inserts in the enrollment's organization and at a location
 * and stamper of the business. It is never updated or deleted, by the model
 * or, through triggers, by anything else. Corrections are new events. The
 * request's IP and user agent belong to the tap log, which can be purged,
 * never to this table, which cannot.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property int $location_id
 * @property int $enrollment_id
 * @property int|null $stamper_id
 * @property int|null $nfc_tag_id
 * @property int|null $staff_id
 * @property StampSource $source
 * @property int $qty
 * @property int|null $counter
 * @property string|null $idempotency_key
 * @property string|null $reason
 * @property Carbon $created_at
 */
#[UseEloquentBuilder(TenantBuilder::class)]
class StampEvent extends Model implements TenantModel
{
    use BelongsToBusiness {
        assertTenantInsert as assertSiteDataInsert;
    }
    use GuardsTenantWrites;

    /** @use HasFactory<StampEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Only the stamp Actions record stamps, after proof of presence (a tap, a
     * staff scan), in bypass(): a business recording one directly could stamp a
     * customer it cannot see, and make them "stamped here". The business must
     * honour the enrollment's card: visibility alone is not enough.
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('Stamps are recorded by the stamp Actions, after proof of presence, in TenantContext::bypass().');
        }

        $this->assertSiteDataInsert($values);

        $honoured = app(TenantContext::class)->bypass(fn (): bool => CardBusiness::query()
            ->where('business_id', $values['business_id'] ?? null)
            ->whereIn('card_id', CardEnrollment::query()->whereKey($values['enrollment_id'] ?? null)->select('card_id'))
            ->exists());

        if (! $honoured) {
            throw new LogicException('A stamp is recorded at a business that honours the card.');
        }
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        throw new LogicException('The stamp ledger is append-only: add a correction event instead.');
    }

    /**
     * @return BelongsTo<CardEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CardEnrollment::class, 'enrollment_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Stamper, $this>
     */
    public function stamper(): BelongsTo
    {
        return $this->belongsTo(Stamper::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => StampSource::class,
            'qty' => 'integer',
            'counter' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
