<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\BusinessMember;
use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Deletes a user's account (CHW-139). The IP and user agent of their taps
 * (the tap log) are scrubbed first. The stamp ledger is permanent and
 * nothing it points at can be deleted, so:
 *
 * - no history: the user is deleted, as before, with their passkeys,
 *   memberships, sessions, reset tokens and cards;
 * - history (a stamp or a reward on their cards, a stamp they gave or a
 *   reward they redeemed as staff): the user is anonymised. The row and its
 *   id stay, so the ledger, counters, rewards and redemption records stay
 *   true, but nothing in it identifies the person (name, email, password,
 *   2FA, locale, passkeys, sessions, reset tokens, referral codes, cards with
 *   nothing on them) and nobody can sign in to it again; the email is free to
 *   sign up with again. The new email is unguessable (and the domain refused
 *   at signup), so nobody can take it first and block the deletion. Changing
 *   the password hash also ends their other sessions (AuthenticateSession),
 *   whatever the session driver.
 *
 * An owner or org admin must hand over or close their business first
 * (ProfileDeleteRequest says so; this refuses too). The user and their cards
 * are locked first, so a stamp cannot slip in between the check and the
 * delete; should the ledger still refuse the delete, the user is anonymised
 * instead. Works across tenants, in bypass().
 */
final readonly class DeleteAccount
{
    /** Anonymised accounts get an address here; signup and profile changes refuse the domain. */
    public const string RESERVED_EMAIL_DOMAIN = 'deleted.invalid';

    public function __construct(
        private TenantContext $context,
        private MustHandOverBusiness $mustHandOverBusiness,
    ) {}

    public function handle(User $user): void
    {
        DB::transaction(fn () => $this->context->bypass(function () use ($user): void {
            // Their taps first, in ApplyTap's order (tap, then enrollment, then the user's key), so the two never deadlock.
            Tap::query()->where('user_id', $user->id)->lockForUpdate()->pluck('id');
            User::query()->whereKey($user->id)->lockForUpdate()->value('id');
            CardEnrollment::query()->where('user_id', $user->id)->lockForUpdate()->pluck('id');

            if ($this->mustHandOverBusiness->handle($user)) {
                throw new LogicException('An owner or org admin hands over or closes the business before deleting their account.');
            }

            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            Tap::query()->where('user_id', $user->id)->update(['ip' => null, 'user_agent' => null]);

            if (! $this->hasHistory($user) && $this->deleted($user)) {
                return;
            }

            $this->anonymise($user);
        }));
    }

    /** Rows kept forever point at this user: stamps or rewards on their cards, stamps they gave or rewards they redeemed as staff. */
    private function hasHistory(User $user): bool
    {
        $cards = CardEnrollment::query()->where('user_id', $user->id)->select('id');

        return StampEvent::query()->where('staff_id', $user->id)->exists()
            || StampEvent::query()->whereIn('enrollment_id', $cards)->exists()
            || Reward::query()->where('redeemed_by', $user->id)->exists()
            || Reward::query()->whereIn('enrollment_id', $cards)->exists();
    }

    /** Deletes the user in a savepoint; false when the ledger refuses after all (history arrived meanwhile). */
    private function deleted(User $user): bool
    {
        try {
            DB::transaction(fn (): ?bool => $user->delete());

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    private function anonymise(User $user): void
    {
        BusinessMember::query()->where('user_id', $user->id)->delete();
        CardEnrollment::query()->where('user_id', $user->id)->whereDoesntHave('stampEvents')->whereDoesntHave('rewards')->delete();
        CardEnrollment::query()->where('user_id', $user->id)->update(['referral_code' => null]);
        $user->passkeys()->delete();

        $user->forceFill([
            'name' => 'Deleted user',
            'email' => 'deleted-'.$user->id.'-'.Str::lower(Str::random(20)).'@'.self::RESERVED_EMAIL_DOMAIN,
            'email_verified_at' => null,
            'password' => Str::random(64),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'locale' => null,
            'anonymised_at' => now(),
        ])->save();
    }
}
