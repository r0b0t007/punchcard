<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Nfc\NfcTagBuilder;
use App\Support\Tenancy\TenantContext;
use Database\Factories\NfcTagFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An NFC tag: platform state, not a tenant's (sun-nfc-verification skill).
 * Admin actions register and re-provision it, the tap endpoint advances
 * last_counter under a row lock, all in TenantContext::bypass(). Outside
 * bypass() it reads as empty (so a past holder cannot watch the next holder's
 * taps) and NfcTagBuilder refuses every write. Database triggers keep it
 * whatever writes it: never deleted or truncated, the uid fixed, the counter
 * and key version only forward, retirement one-way. Stampers assign it to a
 * business; the tag outlives them.
 *
 * @property int $id
 * @property string $uid
 * @property int $key_version
 * @property int $last_counter
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseEloquentBuilder(NfcTagBuilder::class)]
class NfcTag extends Model
{
    /** @use HasFactory<NfcTagFactory> */
    use HasFactory;

    /** @var array<string, mixed> The database defaults, also in memory before a refresh. */
    protected $attributes = ['key_version' => 1, 'last_counter' => 0];

    /**
     * @return HasMany<Stamper, $this>
     */
    public function stampers(): HasMany
    {
        return $this->hasMany(Stamper::class);
    }

    protected static function booted(): void
    {
        static::addGlobalScope(NfcTagBuilder::PLATFORM_SCOPE, static function (Builder $query): void {
            if (! app(TenantContext::class)->isBypassed()) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key_version' => 'integer',
            'last_counter' => 'integer',
            'retired_at' => 'datetime',
        ];
    }
}
