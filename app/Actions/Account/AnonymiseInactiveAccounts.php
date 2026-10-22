<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\CardEnrollment;
use App\Models\Reward;
use App\Models\Tap;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The inactivity rule's sweep (CHW-58, punchcard.privacy): each customer
 * unused for inactive_after_months whose warning's day has come
 * (inactivity_deletes_at, the day the email named, whatever the limits say
 * now) goes the way of a deletion they asked for (DeleteAccount): deleted
 * with no history, else anonymised, the ledger and businesses' counts kept.
 *
 * Checked again once DeleteAccount holds the user row and their cards, also
 * against use already committed that last_active_at, written after each
 * response, may not show yet: a stamp proving they were there, a
 * redemption, a tap or a signed-in request. So a tap, a stamp or a sign-in
 * as the sweep runs keeps the account. An account that fails is logged by
 * id only and left for the next run. Returns how many went.
 */
final readonly class AnonymiseInactiveAccounts
{
    public function __construct(
        private TenantContext $context,
        private InactiveCustomers $inactiveCustomers,
        private DeleteAccount $deleteAccount,
    ) {}

    public function handle(CarbonInterface $now): int
    {
        $now = CarbonImmutable::instance($now)->utc();
        $before = $now->subMonths((int) config('punchcard.privacy.inactive_after_months'));
        $due = fn (): Builder => $this->inactiveCustomers->handle($before)->where('inactivity_deletes_at', '<=', $now);

        return $this->context->bypass(function () use ($due, $before): int {
            $gone = 0;

            foreach ($due()->lazyById(100) as $user) {
                try {
                    $stillDue = fn (): bool => $due()->whereKey($user->id)->exists() && ! $this->usedSince($user->id, $before);
                    $gone += $this->deleteAccount->handle($user, stillDue: $stillDue) ? 1 : 0;
                } catch (Throwable $failed) {
                    // Never the message: a query's names the address.
                    Log::warning('The inactivity sweep skipped an account.', ['user_id' => $user->id, 'error' => $failed::class]);
                }
            }

            return $gone;
        });
    }

    /** Use since $before already committed, read with the user's cards locked (DeleteAccount), so none lands after it. */
    private function usedSince(int $userId, CarbonImmutable $before): bool
    {
        $cards = CardEnrollment::query()->where('user_id', $userId)->select('id');

        return CardEnrollment::query()->where('user_id', $userId)->where('last_stamp_at', '>=', $before)->exists()
            || Reward::query()->whereIn('enrollment_id', $cards)->where('redeemed_at', '>=', $before)->exists()
            || Tap::query()->where('user_id', $userId)->where('created_at', '>=', $before)->exists()
            || DB::table('sessions')->where('user_id', $userId)->where('last_activity', '>=', $before->getTimestamp())->exists();
    }
}
