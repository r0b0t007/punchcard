<?php

declare(strict_types=1);

use App\Actions\Auth\CreateCustomer;
use App\Actions\Auth\RecordConsent;
use App\Models\ConsentEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| News from punchcard: the customer's own switch (CHW-58, C12 Privacy)
|--------------------------------------------------------------------------
|
| Consent to punchcard's news by email is given at sign-up (CHW-23) and
| changed in the settings at any time. Each state keeps its date: on since,
| off since. Turning it on needs a verified email (whoever gives it owns the
| inbox); turning it off always works. A later sign-up form never opts back
| in someone who opted out.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-18 10:00:00');
    $this->user = User::factory()->create(['marketing_opt_in_at' => null]);
    $this->set = fn (bool $on, ?User $as = null) => $this->actingAs($as ?? $this->user)->patch(route('privacy.marketing'), ['marketing' => $on]);
    $this->fresh = fn (): User => $this->user->fresh();
    $this->log = fn (User $user): array => ConsentEvent::query()->where('user_id', $user->id)->orderBy('id')->get()
        ->map(fn (ConsentEvent $event): array => [$event->kind, $event->granted, $event->source, $event->created_at->toDateTimeString()])->all();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('turns news on and off, each state dated', function (): void {
    ($this->set)(true)->assertRedirect(route('privacy.edit'));

    expect(($this->fresh)())
        ->marketing_opt_in_at->toEqual(now())
        ->marketing_opt_out_at->toBeNull();

    Carbon::setTestNow('2026-10-20 09:00:00');
    ($this->set)(false)->assertRedirect(route('privacy.edit'));

    expect(($this->fresh)())
        ->marketing_opt_in_at->toBeNull()
        ->marketing_opt_out_at->toEqual(now());

    // On again: the opt-out is over.
    Carbon::setTestNow('2026-10-22 09:00:00');
    ($this->set)(true);

    expect(($this->fresh)())
        ->marketing_opt_in_at->toEqual(now())
        ->marketing_opt_out_at->toBeNull();
});

it('keeps the date of a state asked for again', function (): void {
    ($this->set)(true);
    Carbon::setTestNow('2026-10-25 09:00:00');
    ($this->set)(true);

    expect(($this->fresh)()->marketing_opt_in_at)->toEqual(Carbon::parse('2026-10-18 10:00:00'));
});

it('turns news on only for a verified email, and off always', function (): void {
    // Ticked at sign-up, before confirming the address.
    $unverified = User::factory()->unverified()->create(['marketing_opt_in_at' => now()]);

    ($this->set)(false, $unverified)->assertSessionHasNoErrors();

    expect($unverified->fresh())
        ->marketing_opt_in_at->toBeNull()
        ->marketing_opt_out_at->toEqual(now());

    ($this->set)(true, $unverified)->assertSessionHasErrors('marketing');
    expect($unverified->fresh()->marketing_opt_in_at)->toBeNull();
});

it('never opts back in someone who opted out, whatever form they fill in later', function (): void {
    ($this->set)(true);
    ($this->set)(false);

    app(RecordConsent::class)->handle(($this->fresh)(), wantsNews: true);
    app(CreateCustomer::class)->handle($this->user->email, 'Salma Tazi', wantsNews: true);

    expect(($this->fresh)()->marketing_opt_in_at)->toBeNull();
});

it('still records news ticked on a later form by someone who never chose', function (): void {
    app(RecordConsent::class)->handle(($this->fresh)(), wantsNews: true);

    expect(($this->fresh)()->marketing_opt_in_at)->toEqual(now());
});

it('takes a yes or a no, and only from someone signed in', function (): void {
    $this->actingAs($this->user)->patch(route('privacy.marketing'), ['marketing' => 'maybe'])->assertSessionHasErrors('marketing');
    auth()->logout();
    $this->patch(route('privacy.marketing'), ['marketing' => true])->assertRedirect(route('login'));
});

it('logs every change, so the consent behind a past message stays known', function (): void {
    ($this->set)(true);
    Carbon::setTestNow('2026-10-20 09:00:00');
    ($this->set)(false);
    ($this->set)(false);
    Carbon::setTestNow('2026-10-22 09:00:00');
    ($this->set)(true);

    expect(($this->log)($this->user))->toBe([
        ['punchcard_news', true, 'settings', '2026-10-18 10:00:00'],
        ['punchcard_news', false, 'settings', '2026-10-20 09:00:00'],
        ['punchcard_news', true, 'settings', '2026-10-22 09:00:00'],
    ]);
});

it('logs news ticked on a sign-up form, and never rewrites a logged change', function (): void {
    app(RecordConsent::class)->handle(($this->fresh)(), wantsNews: true);
    app(RecordConsent::class)->handle(($this->fresh)(), wantsNews: true);

    expect(($this->log)($this->user))->toBe([['punchcard_news', true, 'sign_up', '2026-10-18 10:00:00']])
        ->and(fn () => ConsentEvent::query()->firstOrFail()->forceFill(['granted' => false])->save())->toThrow(LogicException::class);
});

it('never undoes an opt-out made while a sign-up form was open', function (): void {
    $openForm = ($this->fresh)();
    ($this->set)(true);
    ($this->set)(false);

    app(RecordConsent::class)->handle($openForm, wantsNews: true);

    expect(($this->fresh)())
        ->marketing_opt_in_at->toBeNull()
        ->marketing_opt_out_at->toEqual(now());
});

it('sends news only to a confirmed inbox that opted in, on an account in use', function (): void {
    $receives = User::factory()->create(['marketing_opt_in_at' => now()]);
    $unconfirmed = User::factory()->unverified()->create(['marketing_opt_in_at' => now()]);
    $anonymised = User::factory()->create(['marketing_opt_in_at' => now(), 'anonymised_at' => now()]);

    expect($receives->receivesNews())->toBeTrue()
        ->and($unconfirmed->receivesNews())->toBeFalse()
        ->and($anonymised->receivesNews())->toBeFalse()
        ->and($this->user->receivesNews())->toBeFalse()
        ->and(User::query()->newsAudience()->pluck('id')->all())->toBe([$receives->id]);
});
