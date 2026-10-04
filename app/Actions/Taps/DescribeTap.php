<?php

declare(strict_types=1);

namespace App\Actions\Taps;

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

/**
 * The screen a tap's result page shows (CHW-25), with what it needs:
 *
 * - tap/pending (C1): signed out, the café's card with nothing on it yet;
 * - tap/stamped (C2): the card after the stamp, the stamps given and any
 *   reward unlocked;
 * - tap/cooldown: the card, and when the next stamp is possible, in the
 *   location's time;
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
                return ['component' => 'tap/pending', 'props' => ['card' => $this->card($tap, null)]];
            }

            if ($tap->status === TapStatus::Stamped) {
                $event = StampEvent::query()->findOrFail($tap->stamp_event_id);
                $enrollment = CardEnrollment::query()->findOrFail($event->enrollment_id);

                return ['component' => 'tap/stamped', 'props' => [
                    'card' => $this->card($tap, $enrollment),
                    'given' => $event->qty,
                    'rewards' => Reward::query()->where('stamp_event_id', $event->id)->orderBy('milestone')->pluck('reward_text')->all(),
                ]];
            }

            if ($tap->rejection === TapRejection::Cooldown) {
                $location = Location::query()->find($tap->location_id);

                return ['component' => 'tap/cooldown', 'props' => [
                    'card' => $this->card($tap, $this->enrollmentOf($tap)),
                    'availableAt' => $tap->available_at?->setTimezone($location->timezone ?? config('app.timezone'))->format('H:i'),
                ]];
            }

            return ['component' => 'tap/refused', 'props' => ['reason' => $this->reason($tap)]];
        });
    }

    /**
     * The café's card as the customer sees it: their progress on it, or an empty one.
     *
     * @return array<string, mixed>|null
     */
    private function card(Tap $tap, ?CardEnrollment $enrollment): ?array
    {
        $business = Business::query()->with('organization')->find($tap->business_id);
        $card = $enrollment instanceof CardEnrollment
            ? LoyaltyCard::query()->find($enrollment->card_id)
            : LoyaltyCard::query()
                ->whereHas('businesses', fn ($businesses) => $businesses->whereKey($tap->business_id))
                ->orderByDesc('active')
                ->orderBy('id')
                ->first();

        if (! $business instanceof Business || ! $card instanceof LoyaltyCard) {
            return null;
        }

        return [
            'businessName' => $business->name,
            'cardName' => $card->name,
            'stampsRequired' => $card->stamps_required,
            'stampsCollected' => $enrollment instanceof CardEnrollment ? $enrollment->current_stamps : 0,
            'rewardText' => $card->reward_text,
            'brandColor' => $business->organization->brand_color,
            'stampStyle' => $card->stamp_style,
        ];
    }

    /** The tap's customer's card at the tap's business, if they hold one. */
    private function enrollmentOf(Tap $tap): ?CardEnrollment
    {
        return CardEnrollment::query()
            ->where('user_id', $tap->user_id)
            ->whereHas('card.businesses', fn ($businesses) => $businesses->whereKey($tap->business_id))
            ->first();
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
