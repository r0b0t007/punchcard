<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\BusinessRole;
use App\Enums\OrganizationRole;
use App\Models\BusinessMember;
use App\Models\CardEnrollment;
use App\Models\OrganizationMember;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Deletes a user's account (CHW-139). The stamp ledger is permanent and
 * nothing it points at can be deleted, so:
 *
 * - no history (no stamp as a customer or as staff): the user is deleted, as
 *   before, with their passkeys and unstamped cards;
 * - history: the user is anonymised. The row and its id stay, so the ledger,
 *   counters and rewards stay true, but nothing in it identifies the person
 *   (name, email, password, 2FA, passkeys, sessions, reset tokens, referral
 *   codes) and nobody can sign in to it again; the email is free to sign up
 *   with again.
 *
 * Staff memberships are removed either way. An owner or org admin must hand
 * over or close their business first (ProfileDeleteRequest says so; this
 * refuses as a backstop). Works across tenants, in bypass().
 */
final readonly class DeleteAccount
{
    public function __construct(private TenantContext $context) {}

    /** The user owns a business or administers an organization, which they must hand over or close first. */
    public function blockedByOwnership(User $user): bool
    {
        return $this->context->bypass(fn (): bool => BusinessMember::query()->where('user_id', $user->id)->where('role', BusinessRole::Owner)->exists()
            || OrganizationMember::query()->where('user_id', $user->id)->where('role', OrganizationRole::OrgAdmin)->exists());
    }

    public function handle(User $user): void
    {
        if ($this->blockedByOwnership($user)) {
            throw new LogicException('An owner or org admin hands over or closes the business before deleting their account.');
        }

        DB::transaction(fn () => $this->context->bypass(function () use ($user): void {
            BusinessMember::query()->where('user_id', $user->id)->delete();

            if ($this->hasStampHistory($user)) {
                $this->anonymise($user);

                return;
            }

            $user->delete();
        }));
    }

    /** Stamps on the user's cards, or stamps they gave as staff: rows the ledger keeps forever. */
    private function hasStampHistory(User $user): bool
    {
        return StampEvent::query()
            ->where('staff_id', $user->id)
            ->orWhereIn('enrollment_id', CardEnrollment::query()->where('user_id', $user->id)->select('id'))
            ->exists();
    }

    private function anonymise(User $user): void
    {
        $email = $user->email;

        $user->passkeys()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        CardEnrollment::query()->where('user_id', $user->id)->update(['referral_code' => null]);

        $user->forceFill([
            'name' => 'Deleted user',
            'email' => "deleted-{$user->id}@deleted.invalid",
            'email_verified_at' => null,
            'password' => Str::random(64),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'anonymised_at' => now(),
        ])->save();
    }
}
