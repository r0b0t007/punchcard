<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\RewardStatus;
use App\Models\AuditLog;
use App\Models\BusinessMember;
use App\Models\CardEnrollment;
use App\Models\ConsentEvent;
use App\Models\Follow;
use App\Models\OrganizationMember;
use App\Models\Reward;
use App\Models\Stamper;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
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
 *   2FA, locale, passkeys, sessions, reset tokens, referral codes, follows, cards with
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
 *
 * The inactivity sweep (AnonymiseInactiveAccounts) passes stillDue, checked
 * once the user row and their cards are locked: false leaves the account as
 * it is, so a sign-in or a stamp racing the sweep wins. Returns whether the
 * account went.
 *
 * An anonymised account takes no more stamps, cards or redemptions (CHW-253):
 * AddStamps and EnrollCustomer read anonymised_at after the locks taken
 * here, and its rewards not yet redeemed expire with it.
 */
final readonly class DeleteAccount
{
    /** Anonymised accounts get an address here; signup and profile changes refuse the domain. */
    public const string RESERVED_EMAIL_DOMAIN = 'deleted.invalid';

    public function __construct(
        private TenantContext $context,
        private MustHandOverBusiness $mustHandOverBusiness,
    ) {}

    /** @param  (Closure(): bool)|null  $stillDue */
    public function handle(User $user, ?Closure $stillDue = null): bool
    {
        return DB::transaction(fn (): bool => $this->context->bypass(function () use ($user, $stillDue): bool {
            // Their taps first, then the user, then their cards (and rewards, in anonymise()): ApplyTap's order, tap then the
            // user's key, so the two never deadlock. A signed-out tap being claimed has no user yet: it waits on the user.
            Tap::query()->where('user_id', $user->id)->lockForUpdate()->pluck('id');
            User::query()->whereKey($user->id)->lockForUpdate()->value('id');
            CardEnrollment::query()->where('user_id', $user->id)->lockForUpdate()->pluck('id');

            if ($stillDue instanceof Closure && ! $stillDue()) {
                return false;
            }

            if ($this->mustHandOverBusiness->handle($user)) {
                throw new LogicException('An owner or org admin hands over or closes the business before deleting their account.');
            }

            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            Tap::query()->where('user_id', $user->id)->update(['ip' => null, 'user_agent' => null, 'claim_token_hash' => null]);
            // An arming they made gives nothing more (CHW-29). Named by nobody once the account goes (Postgres empties
            // armed_by), it would otherwise read as an arming from before armers were named, and still pay out.
            Stamper::query()->where('armed_by', $user->id)->update(Stamper::DISARMED);

            if (! $this->hasHistory($user) && $this->deleted($user)) {
                return true;
            }

            $this->anonymise($user);

            return true;
        }));
    }

    /** Rows kept forever point at this user: stamps or rewards on their cards, stamps they gave or rewards they redeemed as staff, or what they did as platform admin (the audit log). */
    private function hasHistory(User $user): bool
    {
        $cards = CardEnrollment::query()->where('user_id', $user->id)->select('id');

        return StampEvent::query()->where('staff_id', $user->id)->exists()
            || StampEvent::query()->whereIn('enrollment_id', $cards)->exists()
            || Reward::query()->where('redeemed_by', $user->id)->exists()
            || Reward::query()->whereIn('enrollment_id', $cards)->exists()
            || AuditLog::query()->where('actor_id', $user->id)->exists();
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
        // An org admin's role too (CHW-308): only one of an archived organization gets here (MustHandOverBusiness), and
        // a restore would make "Deleted user" its admin again, counted as one and working at every site.
        OrganizationMember::query()->where('user_id', $user->id)->delete();
        // A deleted row loses its roles with it (HasRoles); a kept one gives them up here.
        $user->syncRoles([]);
        CardEnrollment::query()->where('user_id', $user->id)->whereDoesntHave('stampEvents')->whereDoesntHave('rewards')->delete();
        CardEnrollment::query()->where('user_id', $user->id)->update(['referral_code' => null]);
        // Unused rewards go with the account (the inactivity email says so); a status change, the ledger stays.
        Reward::query()->whereIn('enrollment_id', CardEnrollment::query()->select('id')->where('user_id', $user->id))
            ->where('status', RewardStatus::Available)
            ->update(['status' => RewardStatus::Expired, 'expires_at' => now(), 'redeem_window_opened_at' => null, 'redeem_window_until' => null]);
        $user->passkeys()->delete();
        // Consent to offers is the person's, not the ledger's (CHW-27).
        Follow::query()->where('user_id', $user->id)->delete();
        // The consent history is the person's: it goes with them (CHW-58).
        ConsentEvent::query()->where('user_id', $user->id)->delete();

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
            // Consent is the person's, as their follows are: news by email, and that they were 16 or older (CHW-23).
            'marketing_opt_in_at' => null,
            'marketing_opt_out_at' => null,
            // Nor when it was last used: there is nobody left to warn (CHW-58).
            'last_active_at' => null,
            'inactivity_warned_at' => null,
            'inactivity_deletes_at' => null,
            'adult_confirmed_at' => null,
            'pending_email' => null,
            'pending_email_expires_at' => null,
            'anonymised_at' => now(),
        ])->save();
    }
}
