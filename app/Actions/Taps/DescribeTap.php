<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use App\Models\Tap;
use App\Support\Tenancy\TenantContext;

/**
 * The screen a tap's result page shows (CHW-25), with what it needs:
 *
 * - tap/pending (C1): signed out, the café's card with nothing on it yet
 *   (refused at once when the café has no active card to give);
 * - tap/stamped (C2): the card as that stamp left it (the tap keeps the
 *   count, so a later visit doesn't mix it with newer stamps), the stamps
 *   given and any reward unlocked;
 * - tap/cooldown: the card the tap was refused on, as the refusal found it,
 *   and when the next stamp is possible (day and time) in the location's
 *   time, or now once passed;
 * - tap/refused: a friendly reason, never the raw one (a fraud signal stays
 *   in the tap log): used, expired, limit, unavailable, card or invalid.
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
            if ($tap->status === TapStatus::Pending && $tap->expires_at?->isPast() === false) {
                $card = $this->card($tap, 0);

                return $card === null || ! $card['active']
                    ? ['component' => 'tap/refused', 'props' => ['reason' => 'card']]
                    : ['component' => 'tap/pending', 'props' => ['card' => $card]];
            }

            if ($tap->status === TapStatus::Stamped) {
                return ['component' => 'tap/stamped', 'props' => [
                    'card' => $this->card($tap, $tap->card_stamps),
                    'given' => $tap->qty,
                    'rewards' => Reward::query()->where('stamp_event_id', $tap->stamp_event_id)->orderBy('milestone')->pluck('reward_text')->all(),
                ]];
            }

            if ($tap->rejection === TapRejection::Cooldown) {
                return ['component' => 'tap/cooldown', 'props' => [
                    'card' => $this->card($tap, $tap->card_stamps),
                    'nextStamp' => $this->nextStamp($tap),
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
    private function card(Tap $tap, ?int $stamps): ?array
    {
        $business = Business::query()->with('organization')->find($tap->business_id);
        $card = $tap->card_id !== null
            ? LoyaltyCard::query()->find($tap->card_id)
            : LoyaltyCard::query()->honouredBy((int) $tap->business_id)->first();

        if (! $business instanceof Business || ! $card instanceof LoyaltyCard) {
            return null;
        }

        return [
            'businessName' => $business->name,
            'cardName' => $card->name,
            'stampsRequired' => $card->stamps_required,
            'stampsCollected' => $stamps ?? 0,
            'rewardText' => $card->reward_text,
            'brandColor' => $business->organization->brand_color,
            'stampStyle' => $card->stamp_style,
            'active' => $card->active,
        ];
    }

    /**
     * When the next stamp is possible, in the location's time: now (the
     * cooldown has passed since), today, tomorrow or a later date (a cooldown
     * can be a day or more).
     *
     * @return array{day: 'now'|'today'|'tomorrow'|'later', date: string, time: string}|null
     */
    private function nextStamp(Tap $tap): ?array
    {
        $timezone = Location::query()->whereKey($tap->location_id)->value('timezone') ?? config('app.timezone');
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

    private function reason(Tap $tap): string
    {
        return match ($tap->status === TapStatus::Pending ? TapRejection::Expired : $tap->rejection) {
            TapRejection::Replay => 'used',
            TapRejection::Expired => 'expired',
            TapRejection::DailyCap => 'limit',
            TapRejection::RetiredTag, TapRejection::UnassignedTag, TapRejection::StamperDisabled, TapRejection::SiteClosed => 'unavailable',
            TapRejection::CardInactive, TapRejection::NotHonoured, TapRejection::CardMisconfigured => 'card',
            default => 'invalid',
        };
    }
}
