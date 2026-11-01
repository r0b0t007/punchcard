<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\CampaignStatus;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\Campaign;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The Campaigns page (CHW-42, design 12c)
|--------------------------------------------------------------------------
|
| Whoever runs the business sees its latest campaigns, newest first: when
| each went, in the business's time, and how many it reached; and how many
| of the week's campaigns are left. Never staff, never another business's.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-04 10:02:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->as = fn (User $user, ?Business $business = null) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.($business ?? $this->tenants->a1)->id]);
    $this->campaign = fn (Business $business, array $values = []): Campaign => $this->context->bypass(fn (): Campaign => Campaign::factory()->for($business)->create($values));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the owner the business\'s campaigns, newest first, with when each was sent and who it went to', function (): void {
    // A fixed offset (UTC+4, no daylight saving): Morocco's differs between tz databases.
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());
    $older = ($this->campaign)($this->tenants->a1, ['title' => 'Rainy day tea', 'segment' => 'top_10', 'status' => CampaignStatus::Sent, 'send_at' => '2026-10-28 15:00:00', 'sent_at' => '2026-10-28 15:40:00', 'audience_count' => 10, 'sent_count' => 9]);
    $today = ($this->campaign)($this->tenants->a1, ['title' => 'Slow Tuesday?', 'segment' => 'quiet_14d', 'status' => CampaignStatus::Sent, 'send_at' => '2026-11-04 09:02:00', 'sent_at' => '2026-11-04 09:20:00', 'audience_count' => 31, 'sent_count' => 30]);
    $going = ($this->campaign)($this->tenants->a1, ['title' => 'Going now', 'status' => CampaignStatus::Sending, 'send_at' => '2026-11-04 10:01:00', 'audience_count' => 12]);
    ($this->campaign)($this->tenants->a2, ['title' => 'A sister franchisee\'s']);
    ($this->campaign)($this->tenants->b1, ['title' => 'Another organization\'s']);

    ($this->as)($this->owner)->get(route('campaigns.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('business/campaigns/index')
            ->where('campaigns', [
                ['id' => $going->id, 'title' => 'Going now', 'segment' => 'all', 'status' => 'sending', 'at' => ['day' => 'today', 'date' => '2026-11-04', 'time' => '14:01'], 'people' => 12],
                ['id' => $today->id, 'title' => 'Slow Tuesday?', 'segment' => 'quiet_14d', 'status' => 'sent', 'at' => ['day' => 'today', 'date' => '2026-11-04', 'time' => '13:02'], 'people' => 31],
                ['id' => $older->id, 'title' => 'Rainy day tea', 'segment' => 'top_10', 'status' => 'sent', 'at' => ['day' => 'other', 'date' => '2026-10-28', 'time' => '19:00'], 'people' => 10],
            ])
            ->where('left', 0)
            ->where('max', 3)
            ->where('nav.campaigns', true));
});

it('reads a campaign due but not yet picked up by the queue as sending, and keeps who a canceled one was for', function (): void {
    ($this->campaign)($this->tenants->a1, ['title' => 'Due', 'send_at' => now()->subMinute(), 'audience_count' => 7]);
    ($this->campaign)($this->tenants->a1, ['title' => 'Later', 'send_at' => now()->addDay(), 'audience_count' => 5]);
    ($this->campaign)($this->tenants->a1, ['title' => 'Canceled', 'status' => CampaignStatus::Canceled, 'send_at' => now()->subDays(2), 'audience_count' => 4]);

    ($this->as)($this->owner)->get(route('campaigns.index'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('campaigns.0.title', 'Later')->where('campaigns.0.status', 'scheduled')->where('campaigns.0.people', 5)
            ->where('campaigns.1.title', 'Due')->where('campaigns.1.status', 'sending')->where('campaigns.1.people', 7)
            ->where('campaigns.2.title', 'Canceled')->where('campaigns.2.status', 'canceled')->where('campaigns.2.people', 4));
});

it('keeps the franchise HQ working across its network out: a campaign is one business\'s', function (): void {
    $hq = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $network = fn () => $this->actingAs($hq)->withSession([SetTenant::SESSION_KEY => 'org:'.$this->tenants->orgA->id]);

    $network()->get(route('cards.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('nav.campaigns', false));
    $network()->get(route('campaigns.index'))->assertForbidden();
});

it('says how many of the week\'s campaigns are left, the empty page included', function (): void {
    ($this->as)($this->owner)->get(route('campaigns.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('campaigns', [])->where('left', 3));

    ($this->campaign)($this->tenants->a1, ['status' => CampaignStatus::Sent, 'sent_at' => now()]);
    ($this->campaign)($this->tenants->a1, ['status' => CampaignStatus::Canceled]);

    ($this->as)($this->owner)->get(route('campaigns.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('left', 2));
});

it('keeps the page and its sidebar entry from staff', function (): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);

    ($this->as)($staff)->get(route('counter.show'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('nav.campaigns', false));
    ($this->as)($staff)->get(route('campaigns.index'))->assertForbidden();
});
