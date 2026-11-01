<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\CampaignStatus;
use App\Http\Middleware\SetTenant;
use App\Models\Campaign;
use App\Models\Follow;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The composer (CHW-42, design 12a)
|--------------------------------------------------------------------------
|
| Whoever runs the business composes a campaign: each audience's size and
| who would get it on a phone, the business's active cards to open, how long
| the title may be beside its name, and the week's campaigns left, with when
| the next may go once none is. Never staff.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-04 10:02:00');
    config(['webpush.vapid.public_key' => 'BAppPublicKey', 'webpush.vapid.private_key' => 'app-private-key']);
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->open = fn (User $user) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.$this->tenants->a1->id])->get(route('campaigns.create'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('gives the owner each audience, the business\'s active cards, and how long the title may be', function (): void {
    $follower = User::factory()->create();
    $enrollment = $this->tenants->enroll($follower, $this->tenants->cardA);
    $this->context->bypass(fn () => (new Follow)->forceFill(['organization_id' => $this->tenants->orgA->id, 'user_id' => $follower->id, 'notify' => true])->save());
    $this->tenants->stamp($enrollment, $this->tenants->a1);
    $follower->updatePushSubscription('https://fcm.googleapis.com/fcm/send/1', 'BKey', 'Secret');
    $this->context->bypass(function (): void {
        LoyaltyCard::factory()->for($this->tenants->orgA)->create(['name' => 'Paused card', 'active' => false])->businesses()->attach($this->tenants->a1->id);
        LoyaltyCard::factory()->for($this->tenants->orgA)->create(['name' => 'A2 only'])->businesses()->attach($this->tenants->a2->id);
    });

    ($this->open)($this->owner)
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('business/campaigns/create')
            ->where('business', 'A1')
            ->where('segments', [
                'all' => ['followers' => 1, 'reach' => 1],
                'quiet_14d' => ['followers' => 0, 'reach' => 0],
                'top_10' => ['followers' => 1, 'reach' => 1],
            ])
            ->where('cards', [['id' => $this->tenants->cardA->id, 'name' => 'A card']])
            ->where('left', 3)
            ->where('max', 3)
            ->where('nextAt', null)
            ->where('titleRoom', 35)
            ->where('bodyMax', 120));
});

it('says when the next campaign can go once the week\'s are sent, in the business\'s time', function (): void {
    $this->context->bypass(fn () => $this->tenants->locationOf($this->tenants->a1)->forceFill(['timezone' => 'Asia/Dubai'])->save());
    $this->context->bypass(fn () => Campaign::factory()->for($this->tenants->a1)->count(3)->create(['status' => CampaignStatus::Sent, 'send_at' => now()->subDay(), 'sent_at' => now()->subDay()]));

    ($this->open)($this->owner)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('left', 0)
            ->where('nextAt', ['day' => 'other', 'date' => '2026-11-10', 'time' => '14:02']));
});

it('keeps the composer from staff', function (): void {
    ($this->open)($this->tenants->member(User::factory()->create(), $this->tenants->a1))->assertForbidden();
});
