<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Auth\MailedLink;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Database\Factories\StaffInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An invitation to a business's staff (CHW-146), optionally to one location:
 * a link mailed to the address (signedUrl), good for DAYS and used once
 * (AcceptStaffInvitation). Site data, like a membership: only an owner or org
 * admin invites, and never to an archived business or location. The address
 * is personal data: rows go a month after they were used or ran out.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property int|null $location_id
 * @property string $email
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['email', 'location_id', 'invited_by', 'expires_at'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class StaffInvitation extends Model implements TenantModel
{
    use BelongsToBusiness {
        assertTenantInsert as assertSiteDataInsert;
    }
    use GuardsTenantWrites;

    /** @use HasFactory<StaffInvitationFactory> */
    use HasFactory;

    use MassPrunable {
        pruneAll as private pruneAllRows;
    }

    /** How long an invitation's link works. */
    public const int DAYS = 7;

    /**
     * Only an owner or org admin invites (staff cannot), and nobody to an
     * archived business or location.
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertSiteDataInsert($values);
        ArchivedSites::assertOpen($values['business_id'] ?? null, $values['location_id'] ?? null, 'An invitation', lock: true);
        $this->assertCanManageMembers();
    }

    /**
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        $this->assertCanManageMembers();
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where(fn (Builder $query) => $query
            ->where('expires_at', '<', now()->subMonth())
            ->orWhere('accepted_at', '<', now()->subMonth()));
    }

    /** The scheduled model:prune runs outside a tenant: every business's old invitations, in bypass(). */
    public function pruneAll(int $chunkSize = 1000): int
    {
        return app(TenantContext::class)->bypass(fn (): int => $this->pruneAllRows($chunkSize));
    }

    /**
     * Invitations that can still be accepted: unused and in date.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /** Its link, signed until it expires: what the mail carries (MailedLink::signed). */
    public function signedUrl(): string
    {
        return MailedLink::signed('staff-invitations.show', $this->expires_at, ['invitation' => $this->id]);
    }

    /** Its address, half hidden: k***@example.com. */
    public function maskedEmail(): string
    {
        return MailedLink::maskedEmail($this->email);
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
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    private function assertCanManageMembers(): void
    {
        $context = app(TenantContext::class);

        if (! $context->isBypassed() && ! $context->canManageMembers()) {
            throw new LogicException('Only an owner or org admin invites staff to a business.');
        }
    }
}
