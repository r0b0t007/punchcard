<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Enums\StampSource;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\StampEvent;
use App\Support\Campaigns\CampaignText;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\JoinClause;

/**
 * A sent campaign's results (CHW-48, B10, design 19a): who it was sent to,
 * how many phones accepted it, how many opened it, and the visits the
 * people it reached (CampaignSend::reached) made here in the WINDOW_HOURS
 * after it went, against the same hours BASELINE_DAYS before for the same
 * people. A visit is a day, in the business's time, with a stamp that
 * proves presence (StampSource::presenceValues), as on the dashboard. The
 * visits above that baseline, never fewer than none, times the business's
 * average ticket, are its estimated revenue; none while no ticket is set.
 * Still counting until its window ends. A campaign sent before recipients
 * were recorded (records_sends) has nothing to compare: what it was sent to
 * only. Days and the week before are the business's (its first open site's
 * time, LocalMoment::timezoneOfBusiness): 7 days back on its calendar, so a
 * clock change between them never moves the window. The notification is
 * shown with the business's name today.
 *
 * Read in bypass(), each read kept to the campaign's business and
 * organization: a franchisee's results are its own, the follower's other
 * sites never in them.
 */
final readonly class DescribeCampaignResult
{
    public const int WINDOW_HOURS = 48;

    public const int BASELINE_DAYS = 7;

    public function __construct(private TenantContext $context) {}

    /**
     * @return array{
     *     campaign: array{id: int, title: string, notification: string, body: string, segment: string, at: array{day: 'today'|'other', date: string, time: string}, audience: int, sent: int, opened: int},
     *     results: array{visits: int, baseline: int, extra: int, counting: bool, until: array{day: 'today'|'other', date: string, time: string}}|null,
     *     ticket: int|null,
     *     revenue: int|null,
     * }
     */
    public function handle(Business $business, Campaign $campaign): array
    {
        $timezone = LocalMoment::timezoneOfBusiness($business->id);
        $sentAt = $campaign->sent_at ?? $campaign->send_at;
        $results = $this->context->bypass(fn (): ?array => $this->results($campaign, $sentAt->toImmutable(), $timezone));
        $extra = $results['extra'] ?? 0;

        return [
            'campaign' => [
                'id' => $campaign->id,
                'title' => $campaign->title,
                'notification' => CampaignText::notificationTitle($business->name, $campaign->title),
                'body' => $campaign->body,
                'segment' => $campaign->segment->value,
                'at' => LocalMoment::of($sentAt, $timezone),
                'audience' => $campaign->audience_count,
                'sent' => $campaign->sent_count,
                'opened' => $campaign->open_count,
            ],
            'results' => $results,
            'ticket' => $business->average_ticket,
            'revenue' => $results === null || $business->average_ticket === null ? null : $extra * $business->average_ticket,
        ];
    }

    /** @return array{visits: int, baseline: int, extra: int, counting: bool, until: array{day: 'today'|'other', date: string, time: string}}|null */
    private function results(Campaign $campaign, CarbonImmutable $sentAt, string $timezone): ?array
    {
        if (! $campaign->records_sends) {
            return null;
        }

        $until = $sentAt->addHours(self::WINDOW_HOURS);
        $baselineFrom = $sentAt->setTimezone($timezone)->subDays(self::BASELINE_DAYS)->utc();
        $visits = $this->visits($campaign, $sentAt, $until, $timezone);
        $baseline = $this->visits($campaign, $baselineFrom, $baselineFrom->addHours(self::WINDOW_HOURS), $timezone);

        return [
            'visits' => $visits,
            'baseline' => $baseline,
            'extra' => max(0, $visits - $baseline),
            'counting' => now()->lessThan($until),
            'until' => LocalMoment::of($until, $timezone),
        ];
    }

    /** The days, in the business's time, its reached followers proved they were here, from one moment to (not including) another. */
    private function visits(Campaign $campaign, CarbonInterface $from, CarbonInterface $to, string $timezone): int
    {
        return StampEvent::query()
            ->join('card_enrollments', function (JoinClause $enrollments) use ($campaign): void {
                $enrollments->on('card_enrollments.id', '=', 'stamp_events.enrollment_id')
                    ->where('card_enrollments.organization_id', $campaign->organization_id);
            })
            ->where('stamp_events.organization_id', $campaign->organization_id)
            ->where('stamp_events.business_id', $campaign->business_id)
            ->whereIn('stamp_events.source', StampSource::presenceValues())
            ->where('stamp_events.created_at', '>=', $from)
            ->where('stamp_events.created_at', '<', $to)
            ->whereIn('card_enrollments.user_id', CampaignSend::query()->select('user_id')->where('campaign_id', $campaign->id)->reached())
            ->get(['stamp_events.created_at', 'card_enrollments.user_id'])
            ->map(fn (StampEvent $stamp): string => $stamp->getAttribute('user_id').'|'.$stamp->created_at->copy()->setTimezone($timezone)->format('Y-m-d'))
            ->unique()->count();
    }
}
