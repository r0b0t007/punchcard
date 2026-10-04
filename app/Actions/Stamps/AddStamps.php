<?php

declare(strict_types=1);

namespace App\Actions\Stamps;

use App\Enums\CardMode;
use App\Enums\OrganizationRole;
use App\Enums\RewardStatus;
use App\Enums\RewardType;
use App\Enums\StamperStatus;
use App\Enums\StampRejection;
use App\Enums\StampSource;
use App\Events\EnrollmentChanged;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\CardBusiness;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\OrganizationMember;
use App\Models\Reward;
use App\Models\Stamper;
use App\Models\StampEvent;
use App\Support\Cards\ProgressiveTiers;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single entry point for every stamp (stamp-flow skill, CHW-24). In one
 * transaction, the tap's stamper locked first, then the enrollment row (the
 * lock order /t follows: tag, stamper, enrollment):
 *
 * 1. staff must work at the business; a retried request (same idempotency
 *    key at the business) returns the earlier stamp if it is the same stamp
 *    by the same person, or is refused;
 * 2. the card must be active and honoured by the business and the site open
 *    (a correction may still take back, at most, the stamps given there) and, for a tap, the stamper
 *    current and active;
 * 3. stamps that prove presence (tap, scan, manual) keep to the cooldown (per
 *    customer per card) and the daily cap (per customer per card per
 *    business, today where the stamp is given); an armed tap gives what room
 *    is left, a scan or manual stamp over the cap is refused;
 * 4. the stamp is appended to the ledger, the progress cache follows it and
 *    stamps that add unlock rewards: cyclic cards carry the extra stamps
 *    over, progressive cards unlock each tier once and never reset.
 *
 * A refusal is a StampRejected and writes nothing. EnrollmentChanged follows
 * the commit. Outside bypass() (staff from their tenant) the caller's business
 * must be the stamp's; /t and system jobs call it in bypass(), and the work
 * itself always runs in bypass().
 */
final readonly class AddStamps
{
    public function __construct(private TenantContext $context) {}

    public function handle(CardEnrollment $enrollment, StampRequest $request): StampResult
    {
        if (! $this->context->isBypassed() && $this->context->businessId() !== $request->businessId) {
            throw new LogicException('Stamps are given at the business you work in.');
        }

        // Staff pick the customer for a manual stamp or a correction: only one the
        // business may see (ADR 0006). A scan proves who is there with their QR.
        if (! $this->context->isBypassed() && in_array($request->source, [StampSource::Manual, StampSource::Correction], true)
            && ! CardEnrollment::query()->whereKey($enrollment->id)->exists()) {
            throw new LogicException('Staff stamp or correct only customers their business can see.');
        }

        try {
            $result = $this->attempt($enrollment->id, $request);
        } catch (UniqueConstraintViolationException $duplicate) {
            // A request with a key may have raced another on it: the second attempt
            // finds the first and replays or refuses it. Anything else rethrows again.
            if ($request->idempotencyKey === null) {
                throw $duplicate;
            }

            $result = $this->attempt($enrollment->id, $request);
        }

        if (! $result->replayed) {
            EnrollmentChanged::dispatch(
                $result->enrollment->id,
                (int) $result->enrollment->organization_id,
                $request->businessId,
                array_map(fn (Reward $reward): int => $reward->id, $result->rewards),
            );
        }

        return $result;
    }

    private function attempt(int $enrollmentId, StampRequest $request): StampResult
    {
        return DB::transaction(fn (): StampResult => $this->context->bypass(function () use ($enrollmentId, $request): StampResult {
            $stamper = $request->stamperId === null ? null : Stamper::query()->whereKey($request->stamperId)->lockForUpdate()->first();
            $enrollment = CardEnrollment::query()->whereKey($enrollmentId)->lockForUpdate()->firstOrFail();

            $this->assertStaff($request);

            $earlier = $request->idempotencyKey === null ? null : StampEvent::query()
                ->where('business_id', $request->businessId)
                ->where('idempotency_key', $request->idempotencyKey)
                ->first();

            if ($earlier instanceof StampEvent) {
                return $this->replay($earlier, $enrollment, $request);
            }

            $card = LoyaltyCard::query()->findOrFail($enrollment->card_id);
            $givenHere = $request->isCorrection()
                ? (int) StampEvent::query()->where('enrollment_id', $enrollment->id)->where('business_id', $request->businessId)->sum('qty')
                : 0;
            $honoured = CardBusiness::query()->where('card_id', $card->id)->where('business_id', $request->businessId)->exists() || $givenHere > 0;
            $location = Location::query()->findOrFail($request->locationId);
            $now = now();
            $qty = $request->qty;

            $this->assertAllowed($card, $honoured, $location, $stamper, $request);
            $tiers = $card->mode === CardMode::Progressive && $request->qty > 0 ? $this->tiers($card) : [];

            if ($request->provesPresence()) {
                $this->assertCooldown($enrollment, $card, $now);
                $qty = $this->withinDailyCap($enrollment, $card, $location, $request, $now);
            }

            if ($request->isCorrection() && $givenHere + $qty < 0) {
                throw new StampRejected(StampRejection::CorrectionExceedsGiven);
            }

            if ($enrollment->current_stamps + $qty < 0) {
                throw new StampRejected(StampRejection::CorrectionBelowZero);
            }

            $event = (new StampEvent)->forceFill([
                'enrollment_id' => $enrollment->id,
                'business_id' => $request->businessId,
                'location_id' => $request->locationId,
                'stamper_id' => $request->stamperId,
                'nfc_tag_id' => $request->nfcTagId,
                'counter' => $request->counter,
                'staff_id' => $request->staffId,
                'source' => $request->source,
                'qty' => $qty,
                'idempotency_key' => $request->idempotencyKey,
                'reason' => $request->reason,
                'created_at' => $now,
            ]);
            $event->save();

            $rewards = $this->progress($enrollment, $card, $tiers, $event, $request, $qty, $now);

            return new StampResult($event, $enrollment, $rewards, replayed: false);
        }));
    }

    /** The key's earlier stamp is this one again (same customer, place, source, quantity, staff and reason), or the key is taken. */
    private function replay(StampEvent $earlier, CardEnrollment $enrollment, StampRequest $request): StampResult
    {
        $same = (int) $earlier->enrollment_id === $enrollment->id
            && (int) $earlier->location_id === $request->locationId
            && $earlier->source === $request->source
            && $earlier->qty === $request->qty
            && ($earlier->staff_id === null ? null : (int) $earlier->staff_id) === $request->staffId
            && $earlier->reason === $request->reason;

        if (! $same) {
            throw new StampRejected(StampRejection::IdempotencyConflict);
        }

        $rewards = Reward::query()->where('stamp_event_id', $earlier->id)->orderBy('milestone')->get()->all();

        return new StampResult($earlier, $enrollment, array_values($rewards), replayed: true);
    }

    /** Staff stamp where they work: a member of the business (at their location, if limited to one), or an org admin of its organization. */
    private function assertStaff(StampRequest $request): void
    {
        if ($request->staffId === null) {
            return;
        }

        $member = BusinessMember::query()
            ->where('business_id', $request->businessId)
            ->where('user_id', $request->staffId)
            ->where(fn ($membership) => $membership->whereNull('location_id')->orWhere('location_id', $request->locationId))
            ->exists()
            || OrganizationMember::query()
                ->where('user_id', $request->staffId)
                ->where('role', OrganizationRole::OrgAdmin)
                ->whereIn('organization_id', Business::query()->whereKey($request->businessId)->select('organization_id'))
                ->exists();

        if (! $member) {
            throw new LogicException('Only the business\'s staff, owners or org admins give stamps there.');
        }
    }

    /**
     * Taking stamps back stays possible where they were given, so the ledger
     * stays fixable: on a card switched off, at a business that no longer
     * honours the card, at an archived site. The open-site check locks like
     * the ledger's own (ArchivedSites): the site
     * rows for a stamp without a stamper, the stamper (locked above) for a tap,
     * so a racing archive is a clean SiteClosed or StamperUnavailable.
     */
    private function assertAllowed(LoyaltyCard $card, bool $honoured, Location $location, ?Stamper $stamper, StampRequest $request): void
    {
        if ((int) $location->business_id !== $request->businessId) {
            throw new LogicException('A stamp\'s location belongs to its business.');
        }

        if (! $card->active && ! $request->isCorrection()) {
            throw new StampRejected(StampRejection::CardInactive);
        }

        if (! $honoured) {
            throw new StampRejected(StampRejection::NotHonoured);
        }

        if (! $request->isCorrection() && ! ArchivedSites::isOpen($request->businessId, $request->locationId, lock: $request->stamperId === null)) {
            throw new StampRejected(StampRejection::SiteClosed);
        }

        if ($request->stamperId !== null) {
            $working = $stamper instanceof Stamper
                && $stamper->unassigned_at === null
                && $stamper->status === StamperStatus::Active
                && (int) $stamper->business_id === $request->businessId
                && (int) $stamper->location_id === $request->locationId;

            if (! $working) {
                throw new StampRejected(StampRejection::StamperUnavailable);
            }
        }
    }

    private function assertCooldown(CardEnrollment $enrollment, LoyaltyCard $card, CarbonInterface $now): void
    {
        if ($enrollment->last_stamp_at === null || $card->cooldown_min === 0) {
            return;
        }

        $availableAt = $enrollment->last_stamp_at->toImmutable()->addMinutes($card->cooldown_min);

        if ($availableAt->greaterThan($now)) {
            throw new StampRejected(StampRejection::Cooldown, $availableAt);
        }
    }

    /**
     * The stamps this one may give under the daily cap: today's taps, scans
     * and manual stamps on this card at this business, the day starting at
     * midnight where the stamp is given. An armed tap gives the room left; a
     * scan or manual stamp that does not fit, or any stamp once the cap is
     * reached, is refused.
     */
    private function withinDailyCap(CardEnrollment $enrollment, LoyaltyCard $card, Location $location, StampRequest $request, CarbonInterface $now): int
    {
        if ($card->daily_cap === null) {
            return $request->qty;
        }

        $today = (int) StampEvent::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('business_id', $request->businessId)
            ->whereIn('source', StampSource::presenceValues())
            ->where('created_at', '>=', $now->toImmutable()->setTimezone($location->timezone)->startOfDay()->utc())
            ->sum('qty');
        $room = $card->daily_cap - $today;

        if ($room < 1 || ($request->qty > $room && $request->source !== StampSource::Nfc)) {
            throw new StampRejected(StampRejection::DailyCap);
        }

        return min($request->qty, $room);
    }

    /**
     * Moves the progress cache with the ledger and unlocks the rewards earned:
     * only stamps that add do, so a correction (which takes stamps back) never
     * pays out.
     *
     * @param  list<array{stamps: int, reward: string}>  $tiers  a progressive card's tiers, lowest first
     * @return list<Reward>
     */
    private function progress(CardEnrollment $enrollment, LoyaltyCard $card, array $tiers, StampEvent $event, StampRequest $request, int $qty, CarbonInterface $now): array
    {
        $before = $enrollment->lifetime_stamps;
        $current = $enrollment->current_stamps + $qty;
        $lifetime = $before + $qty;
        $completed = $enrollment->completed_count;
        $rewards = [];

        if ($qty > 0 && $card->mode === CardMode::Cyclic && $current >= $card->stamps_required) {
            // Milestones follow the highest one already there (an import may have added some).
            $milestone = max($completed, (int) Reward::query()->where('enrollment_id', $enrollment->id)->where('mode', CardMode::Cyclic)->max('milestone'));

            while ($current >= $card->stamps_required) {
                $current -= $card->stamps_required;
                $completed++;
                $rewards[] = $this->unlock($enrollment, $card, $event, ++$milestone, $card->reward_text, $now);
            }
        }

        if ($qty > 0 && $card->mode === CardMode::Progressive) {
            $crossed = array_filter($tiers, fn (array $tier): bool => $tier['stamps'] > $before && $tier['stamps'] <= $lifetime);
            $unlocked = $crossed === [] ? [] : Reward::query()->where('enrollment_id', $enrollment->id)->where('mode', CardMode::Progressive)->pluck('milestone')->all();

            foreach ($crossed as $tier) {
                if (! in_array($tier['stamps'], $unlocked, true)) {
                    $completed++;
                    $rewards[] = $this->unlock($enrollment, $card, $event, $tier['stamps'], $tier['reward'], $now);
                }
            }
        }

        $enrollment->forceFill([
            'current_stamps' => $current,
            'lifetime_stamps' => $lifetime,
            'completed_count' => $completed,
            ...($request->provesPresence() ? ['last_stamp_at' => $now] : []),
        ])->save();

        return $rewards;
    }

    /**
     * A progressive card's tiers. They are checked when the card is saved; a
     * card whose stored tiers are still malformed (written before that check,
     * or raw) refuses the stamp cleanly rather than failing the tap.
     *
     * @return list<array{stamps: int, reward: string}>
     */
    private function tiers(LoyaltyCard $card): array
    {
        try {
            return ProgressiveTiers::parse($card->tiers);
        } catch (LogicException) {
            throw new StampRejected(StampRejection::CardMisconfigured);
        }
    }

    /** A reward is a snapshot of what was earned, so a later card edit does not change it. No expiry yet. */
    private function unlock(CardEnrollment $enrollment, LoyaltyCard $card, StampEvent $event, int $milestone, string $text, CarbonInterface $now): Reward
    {
        $cyclic = $card->mode === CardMode::Cyclic;
        $reward = (new Reward)->forceFill([
            'enrollment_id' => $enrollment->id,
            'stamp_event_id' => $event->id,
            'mode' => $card->mode,
            'milestone' => $milestone,
            'reward_type' => $cyclic ? $card->reward_type : RewardType::Item,
            'reward_value' => $cyclic ? $card->reward_value : null,
            'reward_text' => $text,
            'status' => RewardStatus::Available,
            'unlocked_at' => $now,
        ]);
        $reward->save();

        return $reward;
    }
}
