<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\CampaignStatus;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\CardEnrollment;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A campaign's results (CHW-48, B10, design 19a)
|--------------------------------------------------------------------------
|
| Sent, opened, and the visits the people it reached made here in the 48 h
| after it, against the same 48 h a week before: a visit is a day, in the
| business's time, with a stamp proving they were here. The visits above
| that, times the average ticket the owner sets there, are the estimated
| revenue. Whoever runs the business sees them, never staff, never another.
|
*/

beforeEach(function (): void {
    // Sent Tuesday 4 November at 10:00 in Casablanca; now is two days later.
    $this->sentAt = CarbonImmutable::parse('2026-11-04 10:00:00', 'Africa/Casablanca')->utc();
    Carbon::setTestNow($this->sentAt->addDays(3));
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(['locale' => 'fr']), $this->tenants->a1, BusinessRole::Owner);
    $this->campaign = $this->context->bypass(fn (): Campaign => Campaign::factory()->for($this->tenants->a1)->create([
        'status' => CampaignStatus::Sent, 'send_at' => $this->sentAt, 'sent_at' => $this->sentAt, 'audience_count' => 5, 'sent_count' => 3, 'open_count' => 2, 'title' => 'Slow Tuesday?',
    ]));

    // A follower the campaign went to (reached: a phone accepted it, unless told otherwise), with their enrollment.
    $this->recipient = function (bool $reached = true, ?Campaign $campaign = null): CardEnrollment {
        $customer = User::factory()->create();
        $send = $this->context->bypass(fn (): CampaignSend => CampaignSend::factory()->create(['campaign_id' => ($campaign ?? $this->campaign)->id, 'user_id' => $customer->id]));

        if ($reached) {
            $this->context->bypass(fn (): bool => $send->markSent($this->sentAt->addMinute()));
        }

        return $this->tenants->enroll($customer, $this->tenants->cardA);
    };
    // A stamp at a local moment in Casablanca (a QR stamp, which proves they were here, unless told otherwise).
    $this->visit = fn (CardEnrollment $enrollment, string $local, array $overrides = [], ?Business $at = null): StampEvent => $this->tenants->stamp($enrollment, $at ?? $this->tenants->a1, [
        'created_at' => CarbonImmutable::parse($local, 'Africa/Casablanca')->utc(), ...$overrides,
    ]);
    $this->results = fn (?Campaign $campaign = null, ?User $as = null) => $this->actingAs($as ?? $this->owner)->get(route('campaigns.show', ($campaign ?? $this->campaign)->id));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('counts the days its reached followers came in, in the 48 h after and the week before, and the revenue the extra brought', function (): void {
    $first = ($this->recipient)();
    $second = ($this->recipient)();
    $this->context->bypass(fn () => $this->tenants->a1->forceFill(['average_ticket' => 3500])->save());

    // After: the first twice the same day (one visit) and again the next day; the second once.
    ($this->visit)($first, '2026-11-04 10:30:00');
    ($this->visit)($first, '2026-11-04 15:00:00');
    ($this->visit)($first, '2026-11-05 09:00:00');
    ($this->visit)($second, '2026-11-05 18:00:00');
    // The week before: one visit each.
    ($this->visit)($first, '2026-10-28 11:00:00');
    ($this->visit)($second, '2026-10-29 09:00:00');

    ($this->results)()->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('business/campaigns/show')
        ->where('campaign.title', 'Slow Tuesday?')
        ->where('campaign.notification', 'A1 · Slow Tuesday?')
        ->where('campaign.at', ['day' => 'other', 'date' => '2026-11-04', 'time' => '10:00'])
        ->where('campaign.audience', 5)->where('campaign.sent', 3)->where('campaign.opened', 2)
        ->where('results.visits', 3)->where('results.baseline', 2)->where('results.extra', 1)
        ->where('results.counting', false)
        ->where('results.until', ['day' => 'other', 'date' => '2026-11-06', 'time' => '10:00'])
        ->where('ticket', 3500)->where('revenue', 3500));
});

it('counts only stamps proving they came here, by the people a phone accepted it for', function (): void {
    $reached = ($this->recipient)();
    $claimedOnly = ($this->recipient)(reached: false);
    $notSentIt = $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA);

    ($this->visit)($reached, '2026-11-04 12:00:00', ['source' => StampSource::Bonus, 'idempotency_key' => 'bonus-1', 'staff_id' => null]);
    ($this->visit)($reached, '2026-11-05 12:00:00', at: $this->tenants->a2);
    ($this->visit)($claimedOnly, '2026-11-04 12:00:00');
    ($this->visit)($notSentIt, '2026-11-04 12:00:00');

    ($this->results)()->assertInertia(fn (Assert $page): Assert => $page->where('results.visits', 0)->where('results.baseline', 0));
});

it('counts the 48 h from the moment it went, not one after, and the same 48 h a week before', function (): void {
    $enrollment = ($this->recipient)();

    ($this->visit)($enrollment, '2026-11-04 10:00:00');
    ($this->visit)($enrollment, '2026-11-06 10:00:00');
    ($this->visit)($enrollment, '2026-10-28 10:00:00');
    ($this->visit)($enrollment, '2026-10-30 10:00:00');
    ($this->visit)($enrollment, '2026-10-28 09:59:00');

    ($this->results)()->assertInertia(fn (Assert $page): Assert => $page->where('results.visits', 1)->where('results.baseline', 1));
});

it('takes a visit\'s day in the business\'s time: before and after midnight are two', function (): void {
    $enrollment = ($this->recipient)();

    ($this->visit)($enrollment, '2026-11-04 23:30:00');
    ($this->visit)($enrollment, '2026-11-05 00:30:00');

    ($this->results)()->assertInertia(fn (Assert $page): Assert => $page->where('results.visits', 2));
});

it('never counts fewer extra visits than none, nor revenue below nothing', function (): void {
    $enrollment = ($this->recipient)();
    $this->context->bypass(fn () => $this->tenants->a1->forceFill(['average_ticket' => 3500])->save());
    ($this->visit)($enrollment, '2026-10-28 11:00:00');
    ($this->visit)($enrollment, '2026-10-29 11:00:00');

    ($this->results)()->assertInertia(fn (Assert $page): Assert => $page->where('results.visits', 0)->where('results.baseline', 2)
        ->where('results.extra', 0)->where('revenue', 0));
});

it('has no revenue while no average ticket is set', function (): void {
    ($this->recipient)();

    ($this->results)()->assertInertia(fn (Assert $page): Assert => $page->where('ticket', null)->where('revenue', null));
});

it('says it is still counting within the 48 h, and until when', function (): void {
    ($this->recipient)();
    Carbon::setTestNow($this->sentAt->addHours(47));

    ($this->results)()->assertInertia(fn (Assert $page): Assert => $page->where('results.counting', true)
        ->where('results.until', ['day' => 'today', 'date' => '2026-11-06', 'time' => '10:00']));
});

it('shows only what a campaign sent before results were recorded went to', function (): void {
    $this->context->bypass(fn () => $this->tenants->a1->forceFill(['average_ticket' => 3500])->save());
    $this->context->bypass(fn () => $this->campaign->forceFill(['records_sends' => false])->save());

    ($this->results)()->assertInertia(fn (Assert $page): Assert => $page->where('results', null)->where('revenue', null)
        ->where('campaign.audience', 5));
});

it('shows a campaign that reached nobody as such, never as one sent before results', function (): void {
    // Every push failed: their claims were let go, and nothing is left of who it went to.
    ($this->results)()->assertInertia(fn (Assert $page): Assert => $page->where('results.visits', 0)->where('results.baseline', 0)->where('results.extra', 0));
});

it('takes the week before 7 days back on the business\'s calendar, across a clock change', function (): void {
    // Sent 10:00 on 10 February 2027 in Casablanca (UTC+0 from 7 February); the week before was UTC+1.
    $this->sentAt = CarbonImmutable::parse('2027-02-10 10:00:00', 'Africa/Casablanca')->utc();
    Carbon::setTestNow($this->sentAt->addDays(3));
    $this->context->bypass(fn () => $this->campaign->forceFill(['send_at' => $this->sentAt, 'sent_at' => $this->sentAt])->save());
    $enrollment = ($this->recipient)();

    ($this->visit)($enrollment, '2027-02-03 10:30:00');

    ($this->results)()->assertInertia(fn (Assert $page): Assert => $page->where('results.baseline', 1));
});

it('has no results for a campaign not sent', function (CampaignStatus $status): void {
    $campaign = $this->context->bypass(fn (): Campaign => Campaign::factory()->for($this->tenants->a1)->create(['status' => $status, 'send_at' => now()->addDay()]));

    ($this->results)($campaign)->assertNotFound();
})->with([CampaignStatus::Scheduled, CampaignStatus::Canceled]);

it('shows them to whoever runs the business only: never staff, a sister franchisee or another organization', function (string $case): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff);
    $a2 = $this->context->bypass(fn (): Campaign => Campaign::factory()->for($this->tenants->a2)->create(['status' => CampaignStatus::Sent, 'sent_at' => $this->sentAt]));
    $b1 = $this->context->bypass(fn (): Campaign => Campaign::factory()->for($this->tenants->b1)->create(['status' => CampaignStatus::Sent, 'sent_at' => $this->sentAt]));

    match ($case) {
        'staff' => ($this->results)(as: $staff)->assertForbidden(),
        'A2\'s campaign' => ($this->results)($a2)->assertNotFound(),
        'B1\'s campaign' => ($this->results)($b1)->assertNotFound(),
    };
})->with(['staff', 'A2\'s campaign', 'B1\'s campaign']);

it('saves the average ticket in centimes, for the business worked in', function (): void {
    $this->actingAs($this->owner)->from(route('campaigns.show', $this->campaign->id))
        ->patch(route('campaigns.ticket'), ['average_ticket' => 3550])
        ->assertRedirect(route('campaigns.show', $this->campaign->id))->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Ticket moyen enregistré.');

    expect($this->context->bypass(fn (): array => [$this->tenants->a1->refresh()->average_ticket, $this->tenants->a2->refresh()->average_ticket]))->toBe([3550, null]);
});

it('takes a ticket from 1 to 10,000 dirhams, in whole centimes', function (mixed $value): void {
    $this->actingAs($this->owner)->patch(route('campaigns.ticket'), ['average_ticket' => $value])->assertSessionHasErrors('average_ticket');

    expect($this->context->bypass(fn (): ?int => $this->tenants->a1->refresh()->average_ticket))->toBeNull();
})->with([
    'under 1 dirham' => [99],
    'over 10,000 dirhams' => [1_000_001],
    'not a number' => ['35 DH'],
    'a fraction of a centime' => [3550.5],
    'none' => [null],
]);

it('says what a ticket can be in dirhams, in the owner\'s language', function (): void {
    $this->actingAs($this->owner)->patch(route('campaigns.ticket'), ['average_ticket' => 50])
        ->assertSessionHasErrors(['average_ticket' => 'Votre ticket moyen est entre 1 et 10 000 dirhams.']);
});

it('says under the field when the ticket was changed too often', function (): void {
    foreach (range(1, 30) as $save) {
        $this->actingAs($this->owner)->patch(route('campaigns.ticket'), ['average_ticket' => 3500])->assertSessionHasNoErrors();
    }

    $this->actingAs($this->owner)->patch(route('campaigns.ticket'), ['average_ticket' => 3600])
        ->assertSessionHasErrors(['average_ticket' => 'Trop de modifications. Réessayez dans une minute.']);
    expect($this->context->bypass(fn (): ?int => $this->tenants->a1->refresh()->average_ticket))->toBe(3500);
});

it('refuses the ticket from staff', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff);

    $this->actingAs($staff)->patch(route('campaigns.ticket'), ['average_ticket' => 3500])->assertForbidden();
});
