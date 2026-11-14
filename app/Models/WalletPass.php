<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletPlatform;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use SensitiveParameter;

/**
 * A customer's card in a phone's Wallet (CHW-38, ADR 0003): one per
 * enrollment and platform, made when the customer first adds it
 * (IssueApplePass). The serial names it in the pass and in its pass code
 * (PassCode); the authentication token is the pass web service's password
 * (Apple's ApplePass header). Program data of the organization, but only the
 * customer's own Actions and the web service touch it, in bypass(): a
 * business never reads or writes a pass.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $enrollment_id
 * @property WalletPlatform $platform
 * @property string $serial
 * @property string $auth_token
 * @property Carbon $changed_at when its content last changed
 * @property Carbon|null $revoked_at when it stopped counting
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseEloquentBuilder(TenantBuilder::class)]
#[Hidden(['auth_token'])]
class WalletPass extends Model implements TenantModel
{
    use BelongsToOrganization {
        assertTenantInsert as assertProgramDataInsert;
    }
    use GuardsTenantWrites;

    /**
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertProgramDataInsert($values);
        $this->assertBypassed();
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        $this->assertBypassed();
    }

    /** organization_id is the enrollment's, also in bypass(). */
    public function fillTenantColumns(): void
    {
        $this->fillOrganizationFrom(CardEnrollment::class, 'enrollment_id');
    }

    /** Whether a request's token is this pass's (constant time). */
    public function authenticates(#[SensitiveParameter] string $token): bool
    {
        return $token !== '' && hash_equals($this->auth_token, $token);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * @return BelongsTo<CardEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CardEnrollment::class, 'enrollment_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => WalletPlatform::class,
            'auth_token' => 'encrypted',
            'changed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    private function assertBypassed(): void
    {
        if (! app(TenantContext::class)->isBypassed()) {
            throw new LogicException('Only the customer\'s Wallet Actions and the pass web service write a Wallet pass, in TenantContext::bypass().');
        }
    }
}
