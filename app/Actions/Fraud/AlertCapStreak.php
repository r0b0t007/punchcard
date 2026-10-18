<?php

declare(strict_types=1);

namespace App\Actions\Fraud;

use App\Actions\Fraud\Concerns\MailsOwnersOnce;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Notifications\DailyCapStreakMail;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Tells a business's owners that a customer reached a card's daily limit
 * there DAYS days in a row (CHW-36): a regular, or stamps collected for
 * others. A day counts when a stamp that proves presence filled it to the
 * limit (DailyCapReached): the limit as it was that day, and the day as
 * AddStamps counted it, in its site's timezone. Each such day is marked
 * here for KEEP_DAYS days; another business of the card has its own marks.
 *
 * Every window of DAYS days that holds the day just filled is judged, so a
 * day filled late (a tap applied the next day) still completes a streak,
 * whatever order the jobs run in. One email per streak: the window's day
 * before is not full, so a daily regular on a low limit is told once, not
 * every day, and its days are marked told once it is sent, so a day of it
 * handled late (a job held up for days) tells nobody again. Each owner at
 * most once per streak (MailsOwnersOnce). Not for a suspended or closed
 * business. The marks need a shared, lasting cache store: the database one
 * (ADR 0005), never one per process or server.
 */
final readonly class AlertCapStreak
{
    use MailsOwnersOnce;

    public const int DAYS = 3;

    private const int KEEP_DAYS = 7;

    public function __construct(private TenantContext $context) {}

    /** @param  string  $date  the site's local day the stamp filled (Y-m-d) */
    public function handle(int $enrollmentId, int $businessId, string $date): void
    {
        $full = fn (CarbonImmutable $day): bool => Cache::has($this->mark($enrollmentId, $businessId, $day));
        $filled = CarbonImmutable::parse($date);
        Cache::put($this->mark($enrollmentId, $businessId, $filled), true, now()->addDays(self::KEEP_DAYS));

        foreach (range(0, self::DAYS - 1) as $after) {
            $last = $filled->addDays($after);
            $days = array_map(fn (int $back): CarbonImmutable => $last->subDays($back), range(self::DAYS - 1, 0));

            if (! in_array(false, array_map($full, $days), true) && ! $full($last->subDays(self::DAYS))) {
                if (! in_array(true, array_map(fn (CarbonImmutable $day): bool => Cache::has($this->mark($enrollmentId, $businessId, $day, 'told')), $days), true)) {
                    $this->tell($enrollmentId, $businessId, $days, $last);
                }

                return;
            }
        }
    }

    private function mark(int $enrollmentId, int $businessId, CarbonImmutable $day, string $what = 'full'): string
    {
        return "fraud-cap-{$what}:{$enrollmentId}:{$businessId}:{$day->toDateString()}";
    }

    /** @param  list<CarbonImmutable>  $days */
    private function tell(int $enrollmentId, int $businessId, array $days, CarbonImmutable $last): void
    {
        $this->context->bypass(function () use ($enrollmentId, $businessId, $days, $last): void {
            $enrollment = CardEnrollment::query()->with(['card', 'user'])->whereKey($enrollmentId)->first();
            $business = Business::query()->operating()->whereKey($businessId)->first();

            if (! $enrollment instanceof CardEnrollment || ! $business instanceof Business) {
                return;
            }

            $this->mailOwnersOnce($business, "fraud-alert:cap:{$enrollmentId}:{$businessId}:".$last->toDateString(), now()->addDays(self::KEEP_DAYS), new DailyCapStreakMail(
                customer: $enrollment->user?->firstName(),
                card: $enrollment->card->name ?? '',
                business: $business->name,
                days: array_map(fn (CarbonImmutable $day): string => $day->toDateString(), $days),
            ));

            foreach ($days as $day) {
                Cache::put($this->mark($enrollmentId, $businessId, $day, 'told'), true, now()->addDays(self::KEEP_DAYS));
            }
        });
    }
}
