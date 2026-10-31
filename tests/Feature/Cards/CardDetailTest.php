<?php

declare(strict_types=1);

use App\Actions\Cards\SetFollow;
use App\Enums\BusinessStatus;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Models\CardEnrollment;
use App\Models\Follow;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| A card in full (CHW-27, C6) and following its café for offers
|--------------------------------------------------------------------------
|
| One of the customer's cards: the card, its reward and terms, what the
| café tells its customers (hours, phone, links), the latest stamps on it,
| the places that honour it with a way to open them in Maps, and "Follow
| for offers": off until the customer turns it on, kept as consent.
| Anyone else's card is a 404.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->travelTo(CarbonImmutable::parse('2026-10-11 11:00:00', 'UTC'));
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    // UTC+1 all year, whatever Morocco's tz rules do.
    $this->context->bypass(fn () => Location::query()->update(['timezone' => 'Africa/Lagos']));
    $this->customer = User::factory()->create();
    $this->enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->show = fn (?CardEnrollment $enrollment = null, ?User $as = null) => $this->actingAs($as ?? $this->customer)->get(route('app.cards.show', ($enrollment ?? $this->enrollment)->id));
    $this->follow = fn (bool $follow, ?CardEnrollment $enrollment = null) => $this->actingAs($this->customer)->patch(route('app.cards.follow', ($enrollment ?? $this->enrollment)->id), ['follow' => $follow]);
});

it('shows the card with its reward, terms and what the café tells its customers', function (): void {
    $this->context->bypass(fn () => $this->tenants->cardA->forceFill([
        'reward_text' => 'A free coffee',
        'terms' => 'One stamp per visit.',
        'info' => ['hours' => 'Every day, 8:00 to 20:00', 'phone' => '+212 539 000 000', 'links' => [
            ['label' => 'Menu', 'url' => 'https://atlas.example/menu'],
            ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
        ]],
    ])->save());
    $this->context->bypass(fn () => $this->enrollment->forceFill(['current_stamps' => 4])->save());
    $this->tenants->reward($this->enrollment);
    // A reward already redeemed is not ready.
    $this->context->bypass(fn () => Reward::factory()->for($this->enrollment, 'enrollment')->create([
        'milestone' => 2, 'status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_business_id' => $this->tenants->a1->id,
    ]));

    ($this->show)()->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($page) => $page->component('app/card')
            ->where('card.businessName', $this->tenants->orgA->name)
            ->where('card.stampsCollected', 4)
            ->where('card.rewardText', 'A free coffee')
            ->where('terms', 'One stamp per visit.')
            ->where('info', ['hours' => 'Every day, 8:00 to 20:00', 'phone' => '+212 539 000 000', 'links' => [['label' => 'Menu', 'url' => 'https://atlas.example/menu']]])
            ->where('rewardsReady', 1)
            ->where('following', false));
});

it('never shows another customer\'s card, and sends guests to sign in', function (): void {
    $this->get(route('app.cards.show', $this->enrollment->id))->assertRedirect(route('login'));
    $theirs = $this->tenants->enroll(User::factory()->create(), $this->tenants->cardB);

    ($this->show)($theirs)->assertNotFound();
    ($this->follow)(true, $theirs)->assertNotFound();
    expect($this->context->bypass(fn (): int => Follow::query()->count()))->toBe(0);
});

it('lists the latest stamps on the card, newest first, corrections included, where they were', function (): void {
    $this->tenants->stamp($this->enrollment, $this->tenants->a1, ['created_at' => '2026-10-08 09:00:00']);
    $this->tenants->stamp($this->enrollment, $this->tenants->a2, ['created_at' => '2026-10-09 09:00:00']);
    $this->tenants->stamp($this->enrollment, $this->tenants->a2, ['created_at' => '2026-10-10 09:00:00', 'source' => StampSource::Correction, 'qty' => -1, 'reason' => 'Twice']);
    // Another customer's stamps never show.
    $this->tenants->stamp($this->tenants->enroll(User::factory()->create(), $this->tenants->cardA), $this->tenants->a1, ['created_at' => '2026-10-10 10:00:00']);

    ($this->show)()->assertInertia(fn ($page) => $page->has('history', 3)
        ->where('history.0.qty', -1)
        ->where('history.0.sourceLabel', 'Correction')
        ->where('history.0.place', 'A2 · A2 site')
        ->where('history.0.at', ['day' => 'other', 'date' => '2026-10-10', 'time' => '10:00'])
        ->where('history.2.place', 'A1 · A1 site'));
});

it('lists the open places that honour the card, each with a way to find it in Maps', function (): void {
    $this->context->bypass(function (): void {
        $this->tenants->locationOf($this->tenants->a1)->forceFill(['address' => '12 Rue de la Plage, Tanger', 'lat' => 35.7804, 'lng' => -5.8128])->save();
        $this->tenants->locationOf($this->tenants->a2)->forceFill(['address' => '3 Avenue Mohammed V, Tétouan', 'lat' => null, 'lng' => null])->save();
        Location::factory()->create(['business_id' => $this->tenants->a1->id, 'name' => 'Closed site', 'archived_at' => now()]);
    });

    ($this->show)()->assertInertia(fn ($page) => $page->has('venues', 2)
        ->where('venues.0.name', 'A1 site')
        ->where('venues.0.businessName', 'A1')
        ->where('venues.0.mapsUrl', 'https://www.google.com/maps/search/?api=1&query=35.7804000%2C-5.8128000')
        ->where('venues.1.mapsUrl', 'https://www.google.com/maps/search/?api=1&query=3+Avenue+Mohammed+V%2C+T%C3%A9touan'));

    $this->context->bypass(fn () => $this->tenants->a2->forceFill(['status' => BusinessStatus::Suspended])->save());
    ($this->show)()->assertInertia(fn ($page) => $page->has('venues', 1));
});

it('shows a progressive card\'s tiers, those reached marked', function (): void {
    $this->context->bypass(fn () => $this->tenants->cardB->forceFill([
        'mode' => 'progressive', 'tiers' => [['stamps' => 10, 'reward' => 'Cake'], ['stamps' => 5, 'reward' => 'Tea']], 'stamps_required' => 10,
    ])->save());
    $enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardB);
    $this->context->bypass(fn () => $enrollment->forceFill(['lifetime_stamps' => 6])->save());

    ($this->show)($enrollment)->assertInertia(fn ($page) => $page->where('tiers', [
        ['stamps' => 5, 'reward' => 'Tea', 'reached' => true],
        ['stamps' => 10, 'reward' => 'Cake', 'reached' => false],
    ])->where('card.stampsCollected', 6));
});

it('keeps following as consent: off until turned on, turned off kept, one row', function (): void {
    $follows = fn () => $this->context->bypass(fn () => Follow::query()->get());
    // Turning it off before ever turning it on records nothing.
    ($this->follow)(false);
    expect($follows())->toHaveCount(0);

    ($this->follow)(true)->assertRedirect(route('app.cards.show', $this->enrollment->id));
    ($this->show)()->assertInertia(fn ($page) => $page->where('following', true));
    $first = $follows()->sole();

    ($this->follow)(false);
    ($this->show)()->assertInertia(fn ($page) => $page->where('following', false));
    // Turned off, the consent's row stays, dated by its last change.
    expect($follows()->sole())->id->toBe($first->id)->notify->toBeFalse();
    ($this->follow)(true);

    $follows = $follows();
    expect($follows)->toHaveCount(1)
        ->and($follows->first()->id)->toBe($first->id)
        ->and($follows->first()->organization_id)->toBe($this->tenants->orgA->id)
        ->and($follows->first()->user_id)->toBe($this->customer->id)
        ->and($follows->first()->notify)->toBeTrue();
});

it('follows the program the card belongs to: one follow for a franchise\'s cards', function (): void {
    ($this->follow)(true);
    $second = $this->context->bypass(function (): CardEnrollment {
        $card = LoyaltyCard::factory()->for($this->tenants->orgA)->create();
        $card->businesses()->attach($this->tenants->a1->id);

        return $this->tenants->enroll($this->customer, $card);
    });

    ($this->show)($second)->assertInertia(fn ($page) => $page->where('following', true));
});

it('refuses a follow that is not a yes or no', function (): void {
    $this->actingAs($this->customer)->patch(route('app.cards.follow', $this->enrollment->id), ['follow' => 'maybe'])->assertSessionHasErrors('follow');
});

it('reads a card in the same number of queries however long its history', function (): void {
    $count = function (int $stamps): int {
        $enrollment = $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA);
        foreach (range(1, $stamps) as $index) {
            $this->tenants->stamp($enrollment, $index % 2 === 0 ? $this->tenants->a1 : $this->tenants->a2, ['created_at' => sprintf('2026-10-%02d 09:00:00', $index)]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->show)($enrollment, $enrollment->user)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    // The first request also does one-time work (the session, the guard): measure after it.
    $count(1);

    expect($count(2))->toBe($count(8));
});

it('offers no follow for a café that has closed, and refuses one cleanly', function (): void {
    $this->context->bypass(fn () => $this->tenants->orgA->forceFill(['archived_at' => now()])->save());

    ($this->show)()->assertInertia(fn ($page) => $page->where('canFollow', false));
    ($this->follow)(true)->assertSessionHasErrors('follow');
    expect($this->context->bypass(fn (): int => Follow::query()->count()))->toBe(0);
});

it('keeps one follow when it is turned on twice at once', function (): void {
    // The other request's row is already there: it is turned on, never doubled.
    $this->context->bypass(fn () => Follow::query()->create(['user_id' => $this->customer->id, 'organization_id' => $this->tenants->orgA->id, 'notify' => true]));

    app(SetFollow::class)->handle($this->enrollment, true);

    expect($this->context->bypass(fn () => Follow::query()->sole()))->notify->toBeTrue();
});

it('never gives a deleted account a follow back', function (): void {
    $this->customer->forceFill(['anonymised_at' => now()])->save();

    app(SetFollow::class)->handle($this->enrollment, true);

    expect($this->context->bypass(fn (): int => Follow::query()->count()))->toBe(0);
});

it('asks for a verified email before taking consent to offers', function (): void {
    $this->customer->forceFill(['email_verified_at' => null])->save();

    ($this->show)()->assertInertia(fn ($page) => $page->where('mustVerify', true)->where('canFollow', true));
    ($this->follow)(true)->assertSessionHasErrors('follow');
    expect($this->context->bypass(fn (): int => Follow::query()->count()))->toBe(0);
});

it('lets an unverified customer turn following off', function (): void {
    ($this->follow)(true);
    $this->customer->forceFill(['email_verified_at' => null])->save();

    ($this->follow)(false)->assertSessionHasNoErrors();
    expect($this->context->bypass(fn () => Follow::query()->sole()))->notify->toBeFalse();
});

it('shows a card whose stored tiers are malformed, without its tiers', function (): void {
    $this->context->bypass(fn () => $this->tenants->cardB->forceFill([
        'mode' => 'progressive', 'tiers' => [['stamps' => 5, 'reward' => 'Tea'], ['stamps' => 10, 'reward' => 'Cake']], 'stamps_required' => 10,
    ])->save());
    // Written around the guards, as an old row could be.
    DB::table('loyalty_cards')->where('id', $this->tenants->cardB->id)->update(['tiers' => json_encode([['stamps' => 'x']])]);

    ($this->show)($this->tenants->enroll($this->customer, $this->tenants->cardB))->assertOk()
        ->assertInertia(fn ($page) => $page->where('tiers', null));
});

it('gives the card\'s page the customer\'s notifications, for the ask after following (CHW-41, 11a)', function (): void {
    config(['webpush.vapid.public_key' => 'BAppPublicKey', 'webpush.vapid.private_key' => 'app-private-key']);

    ($this->show)()->assertInertia(fn ($page) => $page->component('app/card')
        ->where('push', ['publicKey' => 'BAppPublicKey', 'rewards' => true, 'phones' => []]));
});
