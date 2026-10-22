<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\InactiveAccountMail;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emails each customer whose account would reach the inactivity limit
 * within the notice period (CHW-58, punchcard.privacy): it will be deleted on
 * a given day, warning_days from now, unless they sign in. Once per spell of
 * inactivity: activity withdraws the warning (RecordActivity).
 *
 * The email goes first, then the warning and the day it promised are
 * written, only while the account is still inactive and unwarned: a warning
 * always stands for an email that went out (a crash in between at worst
 * sends a second one later), and a sign-in meanwhile keeps the account
 * unwarned. A warning the current limits no longer call for (used since,
 * the limit raised, now staff) is withdrawn first, so a later one names the
 * right day. At most warnings_per_run emails a run; an account whose email
 * fails is logged by id only and tried again next run. Returns how many
 * were warned.
 */
final readonly class WarnInactiveAccounts
{
    public function __construct(private TenantContext $context, private InactiveCustomers $inactiveCustomers) {}

    public function handle(CarbonInterface $now): int
    {
        $now = CarbonImmutable::instance($now)->utc();
        $days = (int) config('punchcard.privacy.warning_days');
        $before = $now->addDays($days)->subMonths((int) config('punchcard.privacy.inactive_after_months'));
        $deleteOn = $now->addDays($days);
        $cap = (int) config('punchcard.privacy.warnings_per_run');

        return $this->context->bypass(function () use ($now, $before, $deleteOn, $cap): int {
            User::withoutTimestamps(fn (): int => User::query()->whereNotNull('inactivity_warned_at')
                ->whereNotIn('id', $this->inactiveCustomers->handle($before)->select('id'))
                ->update(['inactivity_warned_at' => null, 'inactivity_deletes_at' => null]));

            [$sent, $warned] = [0, 0];

            foreach ($this->inactiveCustomers->handle($before)->whereNull('inactivity_warned_at')->lazyById(100) as $user) {
                if ($sent >= $cap) {
                    break;
                }

                $sent++;

                try {
                    $user->notifyNow(new InactiveAccountMail($deleteOn));
                } catch (Throwable $failed) {
                    // Never the message: a mail server's names the address.
                    Log::warning('The inactivity warning could not be sent.', ['user_id' => $user->id, 'error' => $failed::class]);

                    continue;
                }

                $warned += User::withoutTimestamps(fn (): int => $this->inactiveCustomers->handle($before)->whereKey($user->id)
                    ->whereNull('inactivity_warned_at')->update(['inactivity_warned_at' => $now, 'inactivity_deletes_at' => $deleteOn]));
            }

            return $warned;
        });
    }
}
