<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\CampaignSegment;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Follow;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Who a business's campaign goes to (CHW-42, B9): its organization's
 * followers (follows.notify) who came in, with a presence stamp at this
 * business, the franchise rule of CHW-27 (Follow::constrainToBusiness), so
 * franchisee A1 never reaches who only visited A2. Never an anonymised
 * account. Then the segment:
 *
 * - All: every one of them;
 * - Quiet: no presence stamp here for CampaignSegment::QUIET_DAYS days or more;
 * - Top: the CampaignSegment::TOP with the most stamps (their quantity) here
 *   in CampaignSegment::TOP_DAYS days, the latest stamp first among equals.
 *
 * In bypass(): customer data across the organization, every read kept to the
 * business and its organization. User ids, by id (Top: by rank).
 */
final readonly class CampaignAudience
{
    public function __construct(private TenantContext $context) {}

    /** @return list<int> */
    public function handle(Business $business, CampaignSegment $segment): array
    {
        $visits = $this->context->bypass(fn (): array => $this->visits($business));
        $quietSince = now()->subDays(CampaignSegment::QUIET_DAYS);

        return match ($segment) {
            CampaignSegment::All => array_keys($visits),
            CampaignSegment::Quiet => array_keys(array_filter($visits, fn (array $visit): bool => $visit['last']?->lessThanOrEqualTo($quietSince) === true)),
            CampaignSegment::Top => array_slice(array_keys($this->ranked(array_filter($visits, fn (array $visit): bool => $visit['recent'] > 0))), 0, CampaignSegment::TOP),
        };
    }

    /**
     * The followers who came in, by user id: their last presence stamp here,
     * and their stamps here in the last TOP_DAYS days with the latest of them.
     *
     * @return array<int, array{last: Carbon|null, recent: int, recentAt: Carbon|null}>
     */
    private function visits(Business $business): array
    {
        $since = now()->subDays(CampaignSegment::TOP_DAYS);
        $followers = Follow::query()
            ->where('organization_id', $business->organization_id)
            ->where('notify', true)
            ->whereIn('user_id', User::query()->select('id')->notAnonymised())
            ->select('user_id');
        $here = StampEvent::query()
            ->where('business_id', $business->id)
            ->whereIn('source', StampSource::presenceValues())
            ->whereIn('enrollment_id', CardEnrollment::query()
                ->select('id')
                ->where('organization_id', $business->organization_id)
                ->whereIn('user_id', $followers));

        $rows = (clone $here)
            ->groupBy('enrollment_id')
            ->selectRaw('enrollment_id, max(created_at) as last_at, sum(case when created_at > ? then qty else 0 end) as recent, max(case when created_at > ? then created_at end) as recent_at', [$since, $since])
            ->get();
        $enrollments = CardEnrollment::query()->whereIn('id', $here->select('enrollment_id'))->pluck('user_id', 'id');

        // A customer may hold several of the organization's cards: one row per enrollment, merged per customer.
        $visits = [];

        foreach ($rows as $row) {
            $userId = (int) $enrollments[$row->enrollment_id];
            $visit = $visits[$userId] ?? ['last' => null, 'recent' => 0, 'recentAt' => null];

            $visits[$userId] = [
                'last' => $this->later($visit['last'], $row->getAttribute('last_at')),
                'recent' => $visit['recent'] + (int) $row->getAttribute('recent'),
                'recentAt' => $this->later($visit['recentAt'], $row->getAttribute('recent_at')),
            ];
        }

        ksort($visits);

        return $visits;
    }

    /** The later of a moment known and one read from the database (a string, or null for none). */
    private function later(?Carbon $known, mixed $read): ?Carbon
    {
        if (! is_string($read)) {
            return $known;
        }

        $at = Carbon::parse($read);

        return $known?->greaterThan($at) === true ? $known : $at;
    }

    /**
     * Most stamps first, then the latest stamp, then the oldest account.
     *
     * @param  array<int, array{last: Carbon|null, recent: int, recentAt: Carbon|null}>  $visits
     * @return array<int, array{last: Carbon|null, recent: int, recentAt: Carbon|null}>
     */
    private function ranked(array $visits): array
    {
        uksort($visits, fn (int $a, int $b): int => [$visits[$b]['recent'], $visits[$b]['recentAt']?->getTimestamp(), $a]
            <=> [$visits[$a]['recent'], $visits[$a]['recentAt']?->getTimestamp(), $b]);

        return $visits;
    }
}
