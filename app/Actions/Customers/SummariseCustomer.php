<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\StampSource;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\CustomerVisitDay;
use App\Models\Location;
use App\Models\OrganizationCustomer;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Summarises one customer at one business from the ledger (CHW-44, B4):
 * their visits there, when they would go quiet and be lost, and the rewards
 * they redeemed there. Rewritten whole each time, so a retry, or two runs in
 * either order, leave it right.
 *
 * - A visit is a day with a presence stamp at the business (a tap, a scan,
 *   a stamp added by hand), in the stamp's site's time, as the daily cap
 *   counts it. Corrections and system stamps are not visits.
 * - Their usual gap is the days between their first and last visit over the
 *   visits between; they go quiet after twice that, never sooner than
 *   quiet_min_days, and are lost lost_days after their last visit
 *   (docs/spec.md "Derived metrics"). Never quiet later than lost: someone
 *   who comes monthly or less goes from active to lost.
 * - Each day they came in to each site is kept too (CHW-45,
 *   CustomerVisitDay), for the dashboard, whatever happened since.
 * - Their row across the organization's sites is rewritten too (CHW-365,
 *   OrganizationCustomer, for a franchise's head office): a day at two
 *   sites is one visit, and they go quiet and are lost only when they stop
 *   coming to every site. From their rows at each business and their days
 *   there; none once they are no business's customer.
 * - No visit here, everything given here taken back (a stamp by hand for the
 *   wrong customer, corrected), or an anonymised account: no row.
 * - Under the customer's row lock, so two summaries of them, or one and
 *   their account's deletion, run one after the other: the later one reads
 *   what the earlier committed.
 *
 * In bypass(), every read kept to the business, its organization and the
 * customer's enrollments in it: in a franchise, a visit to A2 never makes an
 * A1 customer.
 */
final readonly class SummariseCustomer
{
    public function __construct(private TenantContext $context) {}

    public function handle(int $businessId, int $organizationId, int $userId): void
    {
        DB::transaction(fn () => $this->context->bypass(function () use ($businessId, $organizationId, $userId): void {
            // One summary of a customer at a time, after the ledger they read is committed, and never beside
            // their account's deletion (DeleteAccount locks the user first too).
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();
            $theirs = CardEnrollment::query()->select('id')->where('organization_id', $organizationId)->where('user_id', $userId);
            $here = StampEvent::query()
                ->where('business_id', $businessId)
                ->whereIn('enrollment_id', $theirs)
                ->whereIn('source', [...StampSource::presenceValues(), StampSource::Correction->value])
                ->get(['location_id', 'source', 'qty', 'created_at']);
            $stamps = $here->reject(fn (StampEvent $stamp): bool => $stamp->source === StampSource::Correction);
            $locations = Location::query()->whereKey($stamps->pluck('location_id')->unique()->values()->all())->get()->keyBy('id');
            // Each presence stamp's site, local day and moment, worked out once.
            $presence = $stamps->map(fn (StampEvent $stamp): array => [
                'location_id' => (int) $stamp->location_id,
                'day' => $stamp->created_at->copy()->setTimezone(LocalMoment::timezoneOf($locations->get($stamp->location_id)))->format('Y-m-d'),
                'at' => $stamp->created_at->copy()->utc()->format('Y-m-d H:i:s'),
            ])->values()->all();
            $row = BusinessCustomer::query()->where('business_id', $businessId)->where('user_id', $userId);

            if ($user instanceof User) {
                $this->recordVisitDays($businessId, $organizationId, $userId, $presence);
            }

            if ($here->sum('qty') <= 0 || ! $user instanceof User || $user->isAnonymised()) {
                $row->delete();
            } else {
                /** @var Carbon $first */
                $first = $stamps->min('created_at');
                /** @var Carbon $last */
                $last = $stamps->max('created_at');

                $days = collect(array_column($presence, 'day'))->unique()->sort()->values();

                BusinessCustomer::query()->updateOrCreate(['business_id' => $businessId, 'user_id' => $userId], [
                    'organization_id' => $organizationId,
                    'first_visit_at' => $first,
                    // Only a redemption sets where a reward was redeemed.
                    'rewards_redeemed' => Reward::query()->whereIn('enrollment_id', $theirs)->where('redeemed_business_id', $businessId)->count(),
                    ...$this->visits($days->count(), $days->first(), $days->last(), $last),
                ]);
            }

            $this->summariseNetwork($organizationId, $userId, $user, $theirs);
        }), attempts: 3);
    }

    /**
     * Their row across the organization's sites (CHW-365): from their rows at each business, and the days they came
     * in there: one visit a day, each in its site's own time as the business rows and the dashboard count it (a
     * franchise across timezones counts a calendar day per site). Their rewards redeemed at any of its sites, a site
     * where they never stamped too (redeeming writes no stamp). None once they are no business's customer, or their
     * account is gone.
     *
     * @param  Builder<CardEnrollment>  $theirs  their enrollments in the organization
     */
    private function summariseNetwork(int $organizationId, int $userId, ?User $user, Builder $theirs): void
    {
        $atBusinesses = BusinessCustomer::query()->where('organization_id', $organizationId)->where('user_id', $userId)
            ->get(['business_id', 'first_visit_at', 'last_visit_at']);
        $row = OrganizationCustomer::query()->where('organization_id', $organizationId)->where('user_id', $userId);

        if ($atBusinesses->isEmpty() || ! $user instanceof User || $user->isAnonymised()) {
            $row->delete();

            return;
        }

        // How many days, the first and the last: counted by the database, never every day of a regular's years.
        $days = CustomerVisitDay::query()->where('organization_id', $organizationId)->where('user_id', $userId)
            ->whereIn('business_id', $atBusinesses->pluck('business_id')->all())
            ->selectRaw('count(distinct visited_on) as days, min(visited_on) as first_day, max(visited_on) as last_day')->first();
        $day = fn (string $key): ?string => $days?->getAttribute($key) === null ? null : substr((string) $days->getAttribute($key), 0, 10);
        /** @var CarbonInterface $first */
        $first = $atBusinesses->min('first_visit_at');
        /** @var CarbonInterface $last */
        $last = $atBusinesses->max('last_visit_at');

        OrganizationCustomer::query()->updateOrCreate(['organization_id' => $organizationId, 'user_id' => $userId], [
            'first_visit_at' => $first,
            'rewards_redeemed' => Reward::query()->whereIn('enrollment_id', $theirs)
                ->whereIn('redeemed_business_id', Business::query()->select('id')->where('organization_id', $organizationId))->count(),
            ...$this->visits((int) $days?->getAttribute('days'), $day('first_day'), $day('last_day'), $last),
        ]);
    }

    /**
     * How many days they came in, their usual gap, and when they go quiet and are lost if they don't come back
     * (docs/spec.md "Derived metrics"): quiet after twice their usual gap, never sooner than quiet_min_days, never
     * later than lost; lost lost_days after their last visit.
     *
     * @param  int  $visits  the days they came in
     * @param  string|null  $firstDay  the first of them (Y-m-d)
     * @param  string|null  $lastDay  the last of them (Y-m-d)
     * @return array{visits: int, last_visit_at: CarbonInterface, usual_gap_days: int|null, quiet_at: CarbonInterface, lost_at: CarbonInterface}
     */
    private function visits(int $visits, ?string $firstDay, ?string $lastDay, CarbonInterface $last): array
    {
        $gap = $visits > 1 ? Carbon::parse((string) $firstDay)->diffInDays(Carbon::parse((string) $lastDay)) / ($visits - 1) : null;
        $quietDays = max((int) config('punchcard.customers.quiet_min_days'), $gap === null ? 0 : (int) ceil(2 * $gap));
        $lostAt = $last->copy()->addDays((int) config('punchcard.customers.lost_days'));

        return [
            'visits' => $visits,
            'last_visit_at' => $last,
            'usual_gap_days' => $gap === null ? null : (int) round($gap),
            'quiet_at' => $last->copy()->addDays($quietDays)->min($lostAt),
            'lost_at' => $lostAt,
        ];
    }

    /**
     * Their days at each site of the business (CHW-45): one row per site and
     * local day with a presence stamp, with the day's latest, made to match
     * the ledger: new and changed days written, and a day no longer found
     * (after a site's timezone changed) removed. The ledger is append-only,
     * so otherwise a day once seen stays, whatever was taken back since, as
     * the dashboard has always counted visits.
     *
     * @param  array<int, array{location_id: int, day: string, at: string}>  $presence  each presence stamp's site, local day and moment (UTC)
     */
    private function recordVisitDays(int $businessId, int $organizationId, int $userId, array $presence): void
    {
        $days = [];

        foreach ($presence as $stamp) {
            $key = $stamp['location_id'].' '.$stamp['day'];

            if (! isset($days[$key]) || $stamp['at'] > $days[$key]['last_at']) {
                $days[$key] = ['organization_id' => $organizationId, 'business_id' => $businessId, 'location_id' => $stamp['location_id'], 'user_id' => $userId, 'visited_on' => $stamp['day'], 'last_at' => $stamp['at']];
            }
        }

        $kept = CustomerVisitDay::query()->where('business_id', $businessId)->where('user_id', $userId)->get(['id', 'location_id', 'visited_on', 'last_at'])
            ->keyBy(fn (CustomerVisitDay $day): string => $day->location_id.' '.$day->visited_on->toDateString());

        // Only what changed: a regular's years of days are not rewritten at each stamp.
        $changed = array_filter($days, fn (array $day, string $key): bool => $kept->get($key)?->last_at->toDateTimeString() !== $day['last_at'], ARRAY_FILTER_USE_BOTH);
        // A day no longer found: the site's timezone changed since, and the visit moved to another day.
        $moved = $kept->reject(fn (CustomerVisitDay $day, string $key): bool => array_key_exists($key, $days))->modelKeys();

        // In chunks: years of visits would pass a database's bound parameters (999 on an old SQLite).
        foreach (array_chunk($moved, 500) as $chunk) {
            CustomerVisitDay::query()->whereKey($chunk)->delete();
        }

        foreach (array_chunk(array_values($changed), 150) as $chunk) {
            CustomerVisitDay::query()->upsert($chunk, ['location_id', 'user_id', 'visited_on'], ['last_at']);
        }
    }
}
