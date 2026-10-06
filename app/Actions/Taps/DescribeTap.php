<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Enums\CardMode;
use App\Enums\StampSource;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\Business;
use App\Models\CardEnrollment;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use App\Models\StampEvent;
use App\Models\Tap;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The screen a tap's result page shows (CHW-25), with what it needs:
 *
 * - tap/pending (C1): signed out, the café's card with nothing on it yet
 *   (refused at once when the café has no active card to give);
 * - tap/stamped (C2): the card as that stamp left it (the tap keeps the
 *   count, so a later visit doesn't mix it with newer stamps) and as it was
 *   before (the stamps that landed animate), the stamps given, any reward
 *   unlocked and when the stamp was given, in the location's time;
 * - tap/redeemed: the reward the tap redeemed instead of stamping (CHW-26),
 *   where and when, in the location's time, for staff to glance at;
 * - tap/cooldown: the card the tap was refused on, as the refusal found it,
 *   how many minutes ago (from now) the stamp that caused the cooldown
 *   landed, and when the next stamp is possible (day and time) in the
 *   location's time, or now once passed;
 * - tap/refused: a friendly reason, never the raw one (a fraud signal stays
 *   in the tap log): used, expired, limit, unavailable, card, redeemed or
 *   invalid
 *   (busy, too many taps, comes from the rate limiter).
 *
 * Reads in bypass(): the viewer is a customer, with no tenant. Only what the
 * customer may see: their own card's progress, the café's public card.
 */
final readonly class DescribeTap
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{component: string, props: array<string, mixed>}
     */
    public function handle(Tap $tap): array
    {
        return $this->context->bypass(function () use ($tap): array {
            $location = Location::query()->select(['id', 'name', 'timezone'])->find($tap->location_id);
            $timezone = $location->timezone ?? (string) config('app.timezone');
            $card = $tap->card_id !== null
                ? LoyaltyCard::query()->find($tap->card_id)
                : LoyaltyCard::query()->honouredBy((int) $tap->business_id)->first();

            if ($tap->status === TapStatus::Pending && $tap->expires_at?->isPast() === false) {
                return $card instanceof LoyaltyCard && $card->active
                    ? ['component' => 'tap/pending', 'props' => ['card' => $this->card($tap, $card, $location, 0)]]
                    : ['component' => 'tap/refused', 'props' => ['reason' => 'card']];
            }

            if ($tap->status === TapStatus::Stamped) {
                $rewards = Reward::query()->where('stamp_event_id', $tap->stamp_event_id)->orderBy('milestone')->pluck('reward_text')->all();
                $completed = $rewards !== [] && $card?->mode === CardMode::Cyclic;

                return ['component' => 'tap/stamped', 'props' => [
                    'card' => $this->card($tap, $card, $location, $completed ? $card->stamps_required : $tap->card_stamps),
                    'stampsBefore' => $this->stampsBefore($tap, $card, count($rewards)),
                    'given' => $tap->qty,
                    'rewards' => $rewards,
                    'stampedAt' => $tap->created_at === null ? null : $this->moment($tap->created_at, $timezone),
                ]];
            }

            if ($tap->status === TapStatus::Redeemed) {
                $reward = Reward::query()->find($tap->reward_id);

                return ['component' => 'tap/redeemed', 'props' => [
                    'card' => $this->card($tap, $card, $location, $tap->card_stamps),
                    'rewardText' => $reward?->reward_text,
                    'redeemedAt' => $reward?->redeemed_at === null ? null : $this->moment($reward->redeemed_at, $timezone),
                ]];
            }

            if ($tap->rejection === TapRejection::Cooldown) {
                return ['component' => 'tap/cooldown', 'props' => [
                    'card' => $this->card($tap, $card, $location, $tap->card_stamps),
                    'nextStamp' => $this->nextStamp($tap, $timezone),
                    'stampedMinutesAgo' => $this->stampedMinutesAgo($tap, $card),
                ]];
            }

            return ['component' => 'tap/refused', 'props' => ['reason' => $this->reason($tap)]];
        });
    }

    /**
     * The café's card as the customer sees it: the tap's card, or before one
     * was picked the card a first tap there would get (LoyaltyCard::honouredBy).
     *
     * @return array<string, mixed>|null
     */
    private function card(Tap $tap, ?LoyaltyCard $card, ?Location $location, ?int $stamps): ?array
    {
        $business = Business::query()->with('organization')->find($tap->business_id);

        if (! $business instanceof Business || ! $card instanceof LoyaltyCard) {
            return null;
        }

        return [
            'businessName' => $business->name,
            'locationName' => $location?->name,
            'cardName' => $card->name,
            'stampsRequired' => $card->stamps_required,
            'stampsCollected' => $stamps ?? 0,
            'rewardText' => $card->reward_text,
            'brandColor' => $business->organization->brand_color,
            'stampStyle' => $card->stamp_style,
        ];
    }

    /**
     * The card's stamps before this tap, so only the slots it filled animate:
     * a cyclic card the stamp completed carried the rest over (one card's
     * worth per reward); a progressive card never resets.
     */
    private function stampsBefore(Tap $tap, ?LoyaltyCard $card, int $rewards): int
    {
        $carried = $card?->mode === CardMode::Cyclic ? $card->stamps_required * $rewards : 0;

        return max(0, (int) $tap->card_stamps + $carried - $tap->qty);
    }

    /**
     * A moment in the location's time: today or another day (the result page
     * can be opened again later), the date and the time.
     *
     * @return array{day: 'today'|'other', date: string, time: string}
     */
    private function moment(CarbonInterface $at, string $timezone): array
    {
        $local = $at->copy()->setTimezone($timezone);

        return [
            'day' => $local->isSameDay(now($timezone)) ? 'today' : 'other',
            'date' => $local->format('Y-m-d'),
            'time' => $local->format('H:i'),
        ];
    }

    /**
     * When the next stamp is possible, in the location's time: now (the
     * cooldown has passed since), today, tomorrow or a later date (a cooldown
     * can be a day or more).
     *
     * @return array{day: 'now'|'today'|'tomorrow'|'later', date: string, time: string}|null
     */
    private function nextStamp(Tap $tap, string $timezone): ?array
    {
        $at = $tap->available_at?->setTimezone($timezone);

        if ($at === null) {
            return null;
        }

        $days = (int) now($timezone)->startOfDay()->diffInDays($at->copy()->startOfDay());

        return [
            'day' => match (true) {
                $at->isPast() => 'now',
                $days <= 0 => 'today',
                $days === 1 => 'tomorrow',
                default => 'later',
            },
            'date' => $at->format('Y-m-d'),
            'time' => $at->format('H:i'),
        ];
    }

    /**
     * How many minutes ago, from now, the stamp that caused the cooldown
     * landed: the newest stamp proving presence inside the card's cooldown
     * before the tap (what AddStamps refused against). Null when the
     * refusal came from a stamp after the tap (a late-applied tap).
     */
    private function stampedMinutesAgo(Tap $tap, ?LoyaltyCard $card): ?int
    {
        if ($tap->user_id === null || ! $card instanceof LoyaltyCard || $tap->created_at === null) {
            return null;
        }

        $last = StampEvent::query()
            ->whereIn('enrollment_id', CardEnrollment::query()->select('id')->where('card_id', $card->id)->where('user_id', $tap->user_id))
            ->whereIn('source', StampSource::presenceValues())
            ->where('created_at', '<=', $tap->created_at)
            ->where('created_at', '>', $tap->created_at->copy()->subMinutes($card->cooldown_min))
            ->max('created_at');

        return is_string($last) ? (int) Carbon::parse($last)->diffInMinutes(now()) : null;
    }

    private function reason(Tap $tap): string
    {
        return match ($tap->status === TapStatus::Pending ? TapRejection::Expired : $tap->rejection) {
            TapRejection::Replay => 'used',
            TapRejection::Expired => 'expired',
            TapRejection::DailyCap => 'limit',
            TapRejection::RetiredTag, TapRejection::UnassignedTag, TapRejection::StamperDisabled, TapRejection::SiteClosed => 'unavailable',
            TapRejection::CardInactive, TapRejection::NotHonoured, TapRejection::CardMisconfigured => 'card',
            TapRejection::AlreadyRedeemed => 'redeemed',
            default => 'invalid',
        };
    }
}
