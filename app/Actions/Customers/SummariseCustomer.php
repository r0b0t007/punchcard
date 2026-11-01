<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\StampSource;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
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
 *   (docs/spec.md "Derived metrics").
 * - No visit here, or an anonymised account: no row.
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
            $stamps = StampEvent::query()
                ->where('business_id', $businessId)
                ->whereIn('enrollment_id', $theirs)
                ->whereIn('source', StampSource::presenceValues())
                ->get(['location_id', 'created_at']);
            $row = BusinessCustomer::query()->where('business_id', $businessId)->where('user_id', $userId);

            if ($stamps->isEmpty() || ! $user instanceof User || $user->isAnonymised()) {
                $row->delete();

                return;
            }

            $timezones = Location::query()->whereKey($stamps->pluck('location_id')->unique()->values()->all())->get()
                ->mapWithKeys(fn (Location $location): array => [$location->id => LocalMoment::timezoneOf($location)]);
            $days = $stamps
                ->map(fn (StampEvent $stamp): string => $stamp->created_at->copy()->setTimezone((string) $timezones->get($stamp->location_id, config('app.timezone')))->format('Y-m-d'))
                ->unique()->sort()->values();
            $visits = $days->count();
            /** @var Carbon $first */
            $first = $stamps->min('created_at');
            /** @var Carbon $last */
            $last = $stamps->max('created_at');
            $gap = $visits > 1 ? Carbon::parse((string) $days->first())->diffInDays(Carbon::parse((string) $days->last())) / ($visits - 1) : null;
            $quietDays = max((int) config('punchcard.customers.quiet_min_days'), $gap === null ? 0 : (int) ceil(2 * $gap));

            BusinessCustomer::query()->updateOrCreate(['business_id' => $businessId, 'user_id' => $userId], [
                'organization_id' => $organizationId,
                'visits' => $visits,
                'first_visit_at' => $first,
                'last_visit_at' => $last,
                'usual_gap_days' => $gap === null ? null : (int) round($gap),
                'quiet_at' => $last->copy()->addDays($quietDays),
                'lost_at' => $last->copy()->addDays((int) config('punchcard.customers.lost_days')),
                // Only a redemption sets where a reward was redeemed.
                'rewards_redeemed' => Reward::query()->whereIn('enrollment_id', $theirs)->where('redeemed_business_id', $businessId)->count(),
            ]);
        }), attempts: 3);
    }
}
