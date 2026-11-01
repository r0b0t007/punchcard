<?php

declare(strict_types=1);

namespace App\Actions\Stamps;

use App\Models\Business;
use App\Models\StampEvent;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The latest stamps given at a business (or one of its locations), newest
 * first: the owner's dashboard (B3) and counter mode's live feed (S1,
 * CHW-29). Each with its customer, card, location, source, how many and
 * when, in the location's time. A customer the user may not see
 * (CardEnrollment's scope) shows without a name; the counter shows first
 * names only (S1: "first name and card"). Staff limited to one location see
 * that location's, whatever the caller asks (TenantContext::locationId()).
 * Each also says how many seconds ago it landed, so the counter can mark the
 * newest "Just now" (CHW-220): the local moment stops at the minute.
 */
final readonly class RecentStamps
{
    public function __construct(private TenantContext $context) {}

    /** How many the feeds list. */
    public const int LISTED = 10;

    /**
     * @return list<array{id: int, customer: ?string, card: ?string, location: ?string, source: string, sourceLabel: string, qty: int, at: array{day: 'today'|'other', date: string, time: string}, secondsAgo: int}>
     */
    public function handle(Business $business, ?int $locationId, CarbonImmutable $now, bool $firstNames = false): array
    {
        $locationId = $this->context->locationId() ?? $locationId;

        return array_values(StampEvent::query()
            ->where('business_id', $business->id)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->with(['enrollment.user:id,name', 'enrollment.card:id,name', 'location:id,name,timezone'])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::LISTED)
            ->get()
            ->map(function (StampEvent $event) use ($now, $firstNames): array {
                $customer = $event->enrollment?->user;

                return [
                    'id' => $event->id,
                    // Staff at the counter see the first name; owners first name and initial (CHW-44), never more.
                    'customer' => $firstNames ? $customer?->firstName() : $customer?->shortName(),
                    'card' => $event->enrollment?->card?->name,
                    'location' => $event->location?->name,
                    'source' => $event->source->value,
                    'sourceLabel' => $event->source->getLabel(),
                    'qty' => $event->qty,
                    'at' => LocalMoment::of($event->created_at, LocalMoment::timezoneOf($event->location), $now),
                    'secondsAgo' => max(0, $now->getTimestamp() - $event->created_at->getTimestamp()),
                ];
            })
            ->all());
    }
}
