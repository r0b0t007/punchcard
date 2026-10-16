<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Cards\CustomerCard;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * My cards (CHW-27, C5): every card the customer holds, the most recently
 * used first (CardEnrollment::lastUsedFirst), each as its program shows it
 * (CustomerCard::ofProgram: the card's colour, else its organization's)
 * with the organization's logo, the rewards ready on it, how many local
 * days ago they last stamped in person (a tap, a scan or by hand, in that
 * location's timezone) and whether the card is paused. A progressive card
 * shows its lifetime stamps, a cyclic one the stamps since its last reward.
 *
 * In bypass(): a customer has no tenant, so every read is kept to their own
 * enrollments. A fixed number of queries, however many cards.
 */
final readonly class ListMyCards
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{
     *     firstName: string,
     *     rewardsReady: int,
     *     cards: list<array{id: int, card: array<string, mixed>, logoUrl: ?string, rewardsReady: int, lastVisitDaysAgo: ?int, paused: bool}>,
     * }
     */
    public function handle(User $user, CarbonImmutable $now): array
    {
        return $this->context->bypass(function () use ($user, $now): array {
            $enrollments = CardEnrollment::query()
                ->where('user_id', $user->id)
                ->lastUsedFirst()
                ->get(['id', 'organization_id', 'card_id', 'current_stamps', 'lifetime_stamps']);
            $enrollmentIds = $enrollments->modelKeys();
            $cards = LoyaltyCard::query()->whereKey($enrollments->pluck('card_id')->unique()->all())->get()->keyBy('id');
            $organizations = Organization::query()
                ->whereKey($enrollments->pluck('organization_id')->unique()->all())
                ->get(['id', 'name', 'brand_color', 'logo_path'])
                ->keyBy('id');
            $ready = Reward::query()
                ->whereIn('enrollment_id', $enrollmentIds)
                ->where('status', RewardStatus::Available)
                ->pluck('enrollment_id')
                ->countBy();
            // The latest stamp in person on each card: the ledger is append-only, so the highest id is the latest.
            $lastVisits = StampEvent::query()
                ->whereIn('id', StampEvent::query()
                    ->whereIn('enrollment_id', $enrollmentIds)
                    ->whereIn('source', StampSource::presenceValues())
                    ->groupBy('enrollment_id')
                    ->selectRaw('max(id)'))
                ->get(['id', 'enrollment_id', 'location_id', 'created_at'])
                ->keyBy('enrollment_id');
            $locations = Location::query()->whereKey($lastVisits->pluck('location_id')->unique()->all())->get(['id', 'timezone'])->keyBy('id');

            $list = [];

            foreach ($enrollments as $enrollment) {
                $card = $cards->get($enrollment->card_id);
                $organization = $organizations->get($enrollment->organization_id);

                if (! $card instanceof LoyaltyCard || ! $organization instanceof Organization) {
                    continue;
                }

                $lastVisit = $lastVisits->get($enrollment->id);
                $timezone = LocalMoment::timezoneOf($lastVisit === null ? null : $locations->get($lastVisit->location_id));

                $list[] = [
                    'id' => $enrollment->id,
                    'card' => CustomerCard::ofProgram($organization, $card, CustomerCard::stampsOf($card, $enrollment)),
                    'logoUrl' => $organization->logoUrl(),
                    'rewardsReady' => (int) $ready->get($enrollment->id, 0),
                    'lastVisitDaysAgo' => $lastVisit === null ? null : (int) round(abs($lastVisit->created_at->toImmutable()->setTimezone($timezone)->startOfDay()
                        ->diffInDays($now->setTimezone($timezone)->startOfDay()))),
                    'paused' => ! $card->active,
                ];
            }

            return [
                'firstName' => $user->firstName() ?? '',
                'rewardsReady' => array_sum(array_column($list, 'rewardsReady')),
                'cards' => $list,
            ];
        });
    }
}
