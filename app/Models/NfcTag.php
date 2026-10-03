<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\TenantContext;
use Database\Factories\NfcTagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An NFC tag: platform state, not a tenant's (sun-nfc-verification skill).
 * Admin actions register and re-provision it, the tap endpoint advances
 * last_counter under a row lock, all in TenantContext::bypass(). Database
 * triggers keep it whatever writes it: never deleted, the uid fixed, the
 * counter and key version only forward, retirement one-way. Stampers assign
 * it to a business; the tag outlives them.
 *
 * @property int $id
 * @property string $uid
 * @property int $key_version
 * @property int $last_counter
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NfcTag extends Model
{
    /** @use HasFactory<NfcTagFactory> */
    use HasFactory;

    /**
     * @return HasMany<Stamper, $this>
     */
    public function stampers(): HasMany
    {
        return $this->hasMany(Stamper::class);
    }

    protected static function booted(): void
    {
        $platformOnly = static function (): void {
            if (! app(TenantContext::class)->isBypassed()) {
                throw new LogicException('NFC tags are platform state: admin actions and the tap endpoint write them, in TenantContext::bypass().');
            }
        };

        static::saving($platformOnly);
        static::deleting($platformOnly);
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
