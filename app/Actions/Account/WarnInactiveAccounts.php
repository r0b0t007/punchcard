<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\InactiveAccountMail;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Emails each customer whose account would reach the inactivity limit
 * within the notice period (CHW-58, punchcard.privacy): it will go on a
 * given day, at least warning_days from now, unless they sign in. Once per
 * spell of inactivity: activity withdraws the warning (RecordActivity).
 *
 * The warning is written first, and only while the account is still
 * inactive and unwarned, so a sign-in racing the job never keeps one; the
 * email then goes out in their language. If it can't be sent, the warning is
 * taken back for the next run: nobody is swept without the email. Returns
 * how many were warned.
 */
final readonly class WarnInactiveAccounts
{
    public function __construct(private TenantContext $context, private InactiveCustomers $inactiveCustomers) {}

    public function handle(CarbonInterface $now): int
    {
        $now = CarbonImmutable::instance($now)->utc();
        $days = (int) config('punchcard.privacy.warning_days');
        $before = $now->addDays($days)->subMonths((int) config('punchcard.privacy.inactive_after_months'));

        return $this->context->bypass(function () use ($now, $days, $before): int {
            $warned = 0;

            foreach ($this->inactiveCustomers->handle($before)->whereNull('inactivity_warned_at')->lazyById(100) as $user) {
                $claimed = User::withoutTimestamps(fn (): int => $this->inactiveCustomers->handle($before)->whereKey($user->id)
                    ->whereNull('inactivity_warned_at')->update(['inactivity_warned_at' => $now]));

                if ($claimed === 0) {
                    continue;
                }

                try {
                    $user->notifyNow(new InactiveAccountMail($now->addDays($days)));
                    $warned++;
                } catch (Throwable $failed) {
                    report($failed);
                    User::withoutTimestamps(fn (): int => User::query()->whereKey($user->id)->where('inactivity_warned_at', $now)->update(['inactivity_warned_at' => null]));
                }
            }

            return $warned;
        });
    }
}
