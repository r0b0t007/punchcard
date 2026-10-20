<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The inactivity rule's sweep (CHW-58, punchcard.privacy): each customer
 * unused for inactive_after_months and warned at least warning_days ago goes
 * the way of a deletion they asked for (DeleteAccount): deleted with no
 * history, else anonymised, the ledger and businesses' counts kept.
 *
 * Checked again once DeleteAccount holds the user row, so a sign-in racing
 * the sweep wins. An account that fails is reported and left for the next
 * run. Returns how many went.
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
        $warnedBy = $now->subDays((int) config('punchcard.privacy.warning_days'));
        $due = fn (): Builder => $this->inactiveCustomers->handle($before)->where('inactivity_warned_at', '<=', $warnedBy);

        return $this->context->bypass(function () use ($due): int {
            $gone = 0;

            foreach ($due()->lazyById(100) as $user) {
                try {
                    $gone += $this->deleteAccount->handle($user, stillDue: fn (): bool => $due()->whereKey($user->id)->exists()) ? 1 : 0;
                } catch (Throwable $failed) {
                    report($failed);
                }
            }

            return $gone;
        });
    }
}
