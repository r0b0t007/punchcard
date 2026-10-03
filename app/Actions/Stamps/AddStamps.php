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
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single entry point for every stamp (stamp-flow skill, CHW-24). In one
 * transaction, with the enrollment row locked:
 *
 * 1. a retried request (same idempotency key at the business) returns the
 *    earlier stamp, or is refused if the key was used for another stamp;
 * 2. the card must be active and honoured by the business, the site open
 *    (corrections excepted) and, for a tap, the stamper current and active;
 * 3. taps and scans keep to the cooldown (per customer per card) and the
 *    daily cap (per customer per business, today where the stamp is given);
 * 4. the stamp is appended to the ledger, the progress cache follows it and
 *    rewards unlock: cyclic cards carry the extra stamps over, progressive
 *    cards unlock each tier once and never reset.
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

        try {
            $result = $this->attempt($enrollment->id, $request);
        } catch (UniqueConstraintViolationException $duplicate) {
            // Two requests raced on one idempotency key: the second now finds the first.
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
            $enrollment = CardEnrollment::query()->whereKey($enrollmentId)->lockForUpdate()->firstOrFail();
            $earlier = $request->idempotencyKey === null ? null : StampEvent::query()
                ->where('business_id', $request->businessId)
                ->where('idempotency_key', $request->idempotencyKey)
                ->first();

            if ($earlier instanceof StampEvent) {
                return $this->replay($earlier, $enrollment, $request);
            }

            $card = LoyaltyCard::query()->findOrFail($enrollment->card_id);
            $location = Location::query()->findOrFail($request->locationId);
            $now = now();

            $this->assertStaff($request);
            $this->assertAllowed($card, $location, $request);

            if ($request->isLimited()) {
                $this->assertCooldown($enrollment, $card, $now);
                $this->assertDailyCap($enrollment, $card, $location, $request, $now);
            }

            if ($enrollment->current_stamps + $request->qty < 0) {
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
                'qty' => $request->qty,
                'idempotency_key' => $request->idempotencyKey,
                'reason' => $request->reason,
                'created_at' => $now,
            ]);
            $event->save();

            $rewards = $this->progress($enrollment, $card, $request, $now);

            return new StampResult($event, $enrollment, $rewards, replayed: false);
        }));
    }

    /** The key's earlier stamp is this one again (same customer, place, source and quantity), or the key is taken. */
    private function replay(StampEvent $earlier, CardEnrollment $enrollment, StampRequest $request): StampResult
    {
        $same = (int) $earlier->enrollment_id === $enrollment->id
            && (int) $earlier->location_id === $request->locationId
            && $earlier->source === $request->source
            && $earlier->qty === $request->qty;

        if (! $same) {
            throw new StampRejected(StampRejection::IdempotencyConflict);
        }

        return new StampResult($earlier, $enrollment, [], replayed: true);
    }

    /** Staff stamp where they work: a member of the business, or an org admin of its organization. */
    private function assertStaff(StampRequest $request): void
    {
        if ($request->staffId === null) {
            return;
        }

        $member = BusinessMember::query()->where('business_id', $request->businessId)->where('user_id', $request->staffId)->exists()
            || OrganizationMember::query()
                ->where('user_id', $request->staffId)
                ->where('role', OrganizationRole::OrgAdmin)
                ->whereIn('organization_id', Business::query()->whereKey($request->businessId)->select('organization_id'))
                ->exists();

        if (! $member) {
            throw new LogicException('Only the business\'s staff, owners or org admins give stamps there.');
        }
    }

    private function assertAllowed(LoyaltyCard $card, Location $location, StampRequest $request): void
    {
        if ((int) $location->business_id !== $request->businessId) {
            throw new LogicException('A stamp\'s location belongs to its business.');
        }

        if (! $card->active) {
            throw new StampRejected(StampRejection::CardInactive);
        }

        if (! CardBusiness::query()->where('card_id', $card->id)->where('business_id', $request->businessId)->exists()) {
            throw new StampRejected(StampRejection::NotHonoured);
        }

        if ($request->source !== StampSource::Correction && ! ArchivedSites::isOpen($request->businessId, $request->locationId)) {
            throw new StampRejected(StampRejection::SiteClosed);
        }

        if ($request->stamperId !== null) {
            $stamper = Stamper::query()->current()->whereKey($request->stamperId)->first();
            $working = $stamper instanceof Stamper
                && $stamper->status === StamperStatus::Active
                && $stamper->business_id === $request->businessId
                && $stamper->location_id === $request->locationId;

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

    /** Today's taps and scans on this card at this business, the day starting at midnight where the stamp is given. */
    private function assertDailyCap(CardEnrollment $enrollment, LoyaltyCard $card, Location $location, StampRequest $request, CarbonInterface $now): void
    {
        if ($card->daily_cap === null) {
            return;
        }

        $today = (int) StampEvent::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('business_id', $request->businessId)
            ->whereIn('source', [StampSource::Nfc, StampSource::Qr])
            ->where('created_at', '>=', $now->toImmutable()->setTimezone($location->timezone)->startOfDay()->utc())
            ->sum('qty');

        if ($today + $request->qty > $card->daily_cap) {
            throw new StampRejected(StampRejection::DailyCap);
        }
    }

    /**
     * Moves the progress cache with the ledger and unlocks the rewards earned.
     *
     * @return list<Reward>
     */
    private function progress(CardEnrollment $enrollment, LoyaltyCard $card, StampRequest $request, CarbonInterface $now): array
    {
        $before = $enrollment->lifetime_stamps;
        $current = $enrollment->current_stamps + $request->qty;
        $lifetime = $before + $request->qty;
        $completed = $enrollment->completed_count;
        $rewards = [];

        if ($card->mode === CardMode::Cyclic) {
            while ($current >= $card->stamps_required) {
                $current -= $card->stamps_required;
                $completed++;
                $rewards[] = $this->unlock($enrollment, $card, $completed, $card->reward_text, $now);
            }
        } else {
            foreach ($this->tiers($card) as $tier) {
                $crossed = $tier['stamps'] > $before && $tier['stamps'] <= $lifetime;

                if ($crossed && ! Reward::query()->where('enrollment_id', $enrollment->id)->where('mode', CardMode::Progressive)->where('milestone', $tier['stamps'])->exists()) {
                    $completed++;
                    $rewards[] = $this->unlock($enrollment, $card, $tier['stamps'], $tier['reward'], $now);
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

    /** A reward is a snapshot of what was earned, so a later card edit does not change it. No expiry yet. */
    private function unlock(CardEnrollment $enrollment, LoyaltyCard $card, int $milestone, string $text, CarbonInterface $now): Reward
    {
        $cyclic = $card->mode === CardMode::Cyclic;
        $reward = (new Reward)->forceFill([
            'enrollment_id' => $enrollment->id,
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

    /**
     * A progressive card's tiers, lowest first. Malformed tiers stop the stamp:
     * a misconfigured card must not silently drop rewards.
     *
     * @return list<array{stamps: int, reward: string}>
     */
    private function tiers(LoyaltyCard $card): array
    {
        $tiers = $card->tiers;

        if (! is_array($tiers) || $tiers === []) {
            throw new LogicException("Progressive card {$card->id} has no tiers.");
        }

        $valid = [];

        foreach ($tiers as $tier) {
            if (! is_array($tier) || ! is_int($tier['stamps'] ?? null) || $tier['stamps'] < 1 || ! is_string($tier['reward'] ?? null) || trim($tier['reward']) === '') {
                throw new LogicException("Progressive card {$card->id} has malformed tiers.");
            }

            $valid[] = ['stamps' => $tier['stamps'], 'reward' => $tier['reward']];
        }

        usort($valid, fn (array $a, array $b): int => $a['stamps'] <=> $b['stamps']);

        return $valid;
    }
}
