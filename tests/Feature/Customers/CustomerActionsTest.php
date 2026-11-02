<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Actions\Account\ExportMyData;
use App\Actions\Customers\SummariseCustomer;
use App\Enums\BusinessRole;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\CustomerNote;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| One customer's actions (CHW-44, B5, design 15c)
|--------------------------------------------------------------------------
|
| Whoever runs the business adds stamps by hand or takes them back, with a
| reason, held to the same rules as any stamp (AddStamps), and keeps notes
| for the team. Staff never; another business's customer is a 404, and a
| franchisee's notes are its own.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->owner = $this->tenants->member(User::factory()->create(['name' => 'Yasmine Alaoui']), $this->tenants->a1, BusinessRole::Owner);
    $this->user = User::factory()->create(['name' => 'Salma Tazi']);
    $this->enrollment = $this->tenants->enroll($this->user, $this->tenants->cardA);
    $this->site = $this->tenants->locationOf($this->tenants->a1);

    // A visit a day ago, so they are A1's customer.
    $this->tenants->stamp($this->enrollment, $this->tenants->a1, ['created_at' => now()->subDay()]);
    $this->summarised = function (?Business $business = null): BusinessCustomer {
        $business ??= $this->tenants->a1;
        app(SummariseCustomer::class)->handle($business->id, $business->organization_id, $this->user->id);

        return $this->context->bypass(fn (): BusinessCustomer => BusinessCustomer::query()->where('business_id', $business->id)->where('user_id', $this->user->id)->sole());
    };
    $this->customer = ($this->summarised)();
    $this->as = fn (User $user, ?Business $business = null) => $this->actingAs($user)
        ->withSession([SetTenant::SESSION_KEY => SetTenant::businessChoice(($business ?? $this->tenants->a1)->id)]);
    $this->send = fn (string $route, array $input = [], ?User $user = null, ?BusinessCustomer $customer = null) => ($this->as)($user ?? $this->owner)
        ->post(route($route, ($customer ?? $this->customer)->id), [
            'qty' => 1, 'enrollment' => $this->enrollment->id, 'location' => $this->site->id, 'reason' => 'Card forgotten at home', 'key' => (string) Str::uuid(), ...$input,
        ]);
    $this->ledger = fn (): array => $this->context->bypass(fn (): array => StampEvent::query()->where('enrollment_id', $this->enrollment->id)
        ->orderBy('id')->get()->map(fn (StampEvent $stamp): array => [$stamp->source->value, $stamp->qty, $stamp->business_id, $stamp->staff_id, $stamp->reason])->all());
    $this->stamps = fn (): int => $this->context->bypass(fn (): int => (int) CardEnrollment::query()->whereKey($this->enrollment->id)->value('current_stamps'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('adds stamps by hand as the owner, with the reason, as a visit', function (): void {
    // The queued summary never runs: the page is right at once all the same.
    Queue::fake();
    $this->context->bypass(fn () => CardEnrollment::query()->whereKey($this->enrollment->id)->update(['current_stamps' => 1]));

    ($this->send)('customers.stamps.store', ['qty' => 2])
        ->assertRedirect(route('customers.show', $this->customer->id))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => trans_choice(':count stamp added.|:count stamps added.', 2)]);

    expect(array_slice(($this->ledger)(), 1))->toBe([['manual', 2, $this->tenants->a1->id, $this->owner->id, 'Card forgotten at home']])
        ->and(($this->stamps)())->toBe(3)
        // Today is a second visit.
        ->and($this->context->bypass(fn (): int => BusinessCustomer::query()->whereKey($this->customer->id)->value('visits')))->toBe(2);
});

it('gives one stamp for one press, however often it is sent', function (): void {
    $key = (string) Str::uuid();

    ($this->send)('customers.stamps.store', ['key' => $key])->assertSessionHasNoErrors();
    ($this->send)('customers.stamps.store', ['key' => $key])->assertSessionHasNoErrors();

    expect(($this->ledger)())->toHaveCount(2);
});

it('says when a reward unlocks', function (): void {
    $this->context->bypass(fn () => CardEnrollment::query()->whereKey($this->enrollment->id)->update(['current_stamps' => $this->tenants->cardA->stamps_required - 1]));

    ($this->send)('customers.stamps.store')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => trans_choice(':count stamp added, and a reward unlocked.|:count stamps added, and a reward unlocked.', 1)]);
});

it('holds stamps by hand to the cooldown and the daily cap, on how many', function (): void {
    // A stamp ten minutes ago, within the 20-minute cooldown.
    $this->tenants->stamp($this->enrollment, $this->tenants->a1, ['created_at' => now()->subMinutes(10)]);

    ($this->send)('customers.stamps.store')->assertSessionHasErrors(['qty' => __('Too soon after their last stamp on this card: the next one can be given from :time today.', [
        'time' => now()->addMinutes(10)->setTimezone($this->site->timezone)->format('H:i'),
    ])]);

    Carbon::setTestNow(now()->addHour());
    ($this->send)('customers.stamps.store', ['qty' => 5])->assertSessionHasErrors(['qty' => __('That goes over today’s limit on this card here.')]);

    expect(($this->ledger)())->toHaveCount(2);
});

it('takes back stamps given here, with the reason, never more than were given here', function (): void {
    $this->context->bypass(fn () => CardEnrollment::query()->whereKey($this->enrollment->id)->update(['current_stamps' => 5]));
    // Stamps at the sister franchisee are not A1's to take back.
    $this->tenants->stamp($this->enrollment, $this->tenants->a2, ['qty' => 3, 'created_at' => now()->subHours(2)]);

    ($this->send)('customers.corrections.store', ['qty' => 2, 'reason' => 'Given twice'])
        ->assertSessionHasErrors(['qty' => __('That is more than this business gave on this card.')]);
    ($this->send)('customers.corrections.store', ['qty' => 1, 'reason' => 'Given twice'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => trans_choice(':count stamp taken back.|:count stamps taken back.', 1)]);

    expect(array_slice(($this->ledger)(), 2))->toBe([['correction', -1, $this->tenants->a1->id, $this->owner->id, 'Given twice']])
        ->and(($this->stamps)())->toBe(4);
});

it('never takes a card below zero', function (): void {
    ($this->send)('customers.corrections.store')->assertSessionHasErrors(['qty' => __('The card doesn’t have that many stamps.')]);
});

it('asks how many, which card, where and why', function (array $input, string $field): void {
    ($this->send)('customers.stamps.store', $input)->assertSessionHasErrors($field);

    expect(($this->ledger)())->toHaveCount(1);
})->with([
    'none' => [['qty' => 0], 'qty'],
    'too many' => [['qty' => 51], 'qty'],
    'no reason' => [['reason' => ''], 'reason'],
    'a reason too long' => [['reason' => str_repeat('a', 256)], 'reason'],
    'no key' => [['key' => ''], 'key'],
    'no card' => [['enrollment' => ''], 'enrollment'],
    'no site' => [['location' => ''], 'location'],
]);

it('stamps only their cards the business sees, at its own open sites', function (string $case): void {
    $input = match ($case) {
        // Another of A1's customers, so their card is one A1 sees.
        'another customer\'s card' => ['enrollment' => tap(
            $this->tenants->enroll(User::factory()->create(), $this->tenants->cardA),
            fn (CardEnrollment $enrollment) => $this->tenants->stamp($enrollment, $this->tenants->a1),
        )->id],
        'a card only stamped at the sister' => ['enrollment' => tap(
            $this->tenants->enroll($this->user, $this->context->bypass(fn (): LoyaltyCard => tap(LoyaltyCard::factory()->for($this->tenants->orgA)->create(), fn (LoyaltyCard $card) => $card->businesses()->attach([$this->tenants->a1->id, $this->tenants->a2->id])))),
            fn (CardEnrollment $enrollment) => $this->tenants->stamp($enrollment, $this->tenants->a2),
        )->id],
        'the sister\'s site' => ['location' => $this->tenants->locationOf($this->tenants->a2)->id],
        'an archived site' => ['location' => $this->context->bypass(fn (): Location => Location::factory()->for($this->tenants->a1)->create(['archived_at' => now()]))->id],
    };

    ($this->send)('customers.stamps.store', $input)->assertSessionHasErrors(array_key_first($input));
})->with(['another customer\'s card', 'a card only stamped at the sister', 'the sister\'s site', 'an archived site']);

it('leaves stamps and notes to whoever runs the business, and another business\'s customer is not found', function (string $route): void {
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);
    $a2Owner = $this->tenants->member(User::factory()->create(), $this->tenants->a2, BusinessRole::Owner);

    ($this->send)($route, ['body' => 'Prefers oat milk'], $staff)->assertForbidden();
    ($this->as)($a2Owner, $this->tenants->a2)->post(route($route, $this->customer->id), ['body' => 'x'])->assertNotFound();

    expect(($this->ledger)())->toHaveCount(1)
        ->and($this->context->bypass(fn (): int => CustomerNote::query()->count()))->toBe(0);
})->with(['customers.stamps.store', 'customers.corrections.store', 'customers.notes.store']);

it('keeps notes for the team, newest first, signed with the short name', function (): void {
    ($this->send)('customers.notes.store', ['body' => 'Prefers oat milk.'])->assertRedirect(route('customers.show', $this->customer->id))->assertSessionHasNoErrors();
    Carbon::setTestNow(now()->addMinute());
    ($this->send)('customers.notes.store', ['body' => 'Comes with her sister on Fridays.']);
    // Another customer's note is theirs alone.
    $someoneElse = User::factory()->create();
    $this->context->bypass(fn () => CustomerNote::query()->create(['business_id' => $this->tenants->a1->id, 'user_id' => $someoneElse->id, 'body' => 'Not hers.']));

    ($this->as)($this->owner)->get(route('customers.show', $this->customer->id))->assertInertia(fn (Assert $page): Assert => $page
        ->where('notes.0.body', 'Comes with her sister on Fridays.')
        ->where('notes.0.by', 'Yasmine A.')
        ->where('notes.1.body', 'Prefers oat milk.')
        ->has('notes', 2)
        ->where('locations', [['id' => $this->site->id, 'name' => $this->site->name]])
        ->where('maxQty', 50));
});

it('keeps a note to 1000 characters', function (): void {
    ($this->send)('customers.notes.store', ['body' => str_repeat('a', 1001)])->assertSessionHasErrors('body');
    ($this->send)('customers.notes.store', ['body' => ''])->assertSessionHasErrors('body');
    ($this->send)('customers.notes.store', ['body' => "\u{200B}\u{2066}\u{2069}"])->assertSessionHasErrors('body');

    expect($this->context->bypass(fn (): int => CustomerNote::query()->count()))->toBe(0);
});

it('never shows a franchisee the sister\'s notes on the same person', function (): void {
    ($this->send)('customers.notes.store', ['body' => 'Prefers oat milk.']);
    $this->tenants->stamp($this->enrollment, $this->tenants->a2, ['created_at' => now()->subDay()]);
    $a2Owner = $this->tenants->member(User::factory()->create(), $this->tenants->a2, BusinessRole::Owner);

    ($this->as)($a2Owner, $this->tenants->a2)->get(route('customers.show', ($this->summarised)($this->tenants->a2)->id))
        ->assertInertia(fn (Assert $page): Assert => $page->where('notes', []));
});

it('deletes a note, but only one on this customer at this business', function (): void {
    ($this->send)('customers.notes.store', ['body' => 'Prefers oat milk.']);
    $note = $this->context->bypass(fn (): CustomerNote => CustomerNote::query()->sole());
    $someoneElse = User::factory()->create();
    $this->tenants->stamp($this->tenants->enroll($someoneElse, $this->tenants->cardA), $this->tenants->a1);
    app(SummariseCustomer::class)->handle($this->tenants->a1->id, $this->tenants->orgA->id, $someoneElse->id);
    $theirs = $this->context->bypass(fn (): BusinessCustomer => BusinessCustomer::query()->where('user_id', $someoneElse->id)->sole());

    ($this->as)($this->owner)->delete(route('customers.notes.destroy', [$theirs->id, $note->id]))->assertNotFound();
    ($this->as)($this->owner)->delete(route('customers.notes.destroy', [$this->customer->id, $note->id]))
        ->assertRedirect(route('customers.show', $this->customer->id))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => __('Note deleted.')]);

    expect($this->context->bypass(fn (): int => CustomerNote::query()->count()))->toBe(0);
});

it('never lets staff delete a note', function (): void {
    ($this->send)('customers.notes.store', ['body' => 'Prefers oat milk.']);
    $note = $this->context->bypass(fn (): CustomerNote => CustomerNote::query()->sole());
    $staff = $this->tenants->member(User::factory()->create(), $this->tenants->a1);

    ($this->as)($staff)->delete(route('customers.notes.destroy', [$this->customer->id, $note->id]))->assertForbidden();

    expect($this->context->bypass(fn (): int => CustomerNote::query()->count()))->toBe(1);
});

it('gives the customer the notes about them, by "staff", and deletes them with their account', function (): void {
    ($this->send)('customers.notes.store', ['body' => 'Prefers oat milk.']);

    expect(app(ExportMyData::class)->handle($this->user, CarbonImmutable::now())['notes_about_you'])
        ->toBe([['business' => 'A1', 'written_at' => '2026-11-01T12:00:00+00:00', 'by' => 'staff', 'text' => 'Prefers oat milk.']]);

    app(DeleteAccount::class)->handle($this->user);

    expect($this->context->bypass(fn (): int => CustomerNote::query()->count()))->toBe(0);
});

it('leaves a note unsigned once its author\'s account is deleted', function (): void {
    ($this->send)('customers.notes.store', ['body' => 'Prefers oat milk.']);
    $this->context->bypass(fn () => $this->owner->forceFill(['anonymised_at' => now()])->save());
    $other = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);

    ($this->as)($other)->get(route('customers.show', $this->customer->id))->assertInertia(fn (Assert $page): Assert => $page
        ->where('notes.0.by', null));
});

it('fills the daily cap exactly, and refuses a stamp past it', function (): void {
    // Five a day on the card: one an hour ago, so four fit.
    $this->tenants->stamp($this->enrollment, $this->tenants->a1, ['created_at' => now()->subHour()]);

    ($this->send)('customers.stamps.store', ['qty' => 4])->assertSessionHasNoErrors();
    Carbon::setTestNow(now()->addHour());
    ($this->send)('customers.stamps.store')->assertSessionHasErrors(['qty' => __('That goes over today’s limit on this card here.')]);
});

it('gives the next stamp when the cooldown ends, not a minute before', function (): void {
    $this->tenants->stamp($this->enrollment, $this->tenants->a1, ['created_at' => now()->subMinutes(19)]);

    ($this->send)('customers.stamps.store')->assertSessionHasErrors('qty');
    Carbon::setTestNow(now()->addMinute());
    ($this->send)('customers.stamps.store')->assertSessionHasNoErrors();
});

it('takes back once for one press, and refuses its key for other details', function (): void {
    // Two given here, so one taken back leaves them a customer.
    $this->tenants->stamp($this->enrollment, $this->tenants->a1, ['created_at' => now()->subDays(2)]);
    $this->context->bypass(fn () => CardEnrollment::query()->whereKey($this->enrollment->id)->update(['current_stamps' => 1]));
    $key = (string) Str::uuid();

    ($this->send)('customers.corrections.store', ['key' => $key])->assertSessionHasNoErrors();
    ($this->send)('customers.corrections.store', ['key' => $key])->assertSessionHasNoErrors();
    ($this->send)('customers.corrections.store', ['key' => $key, 'reason' => 'Given twice'])
        ->assertSessionHasErrors(['qty' => __('This was already recorded. Reload the page to see it.')]);

    expect(($this->ledger)())->toHaveCount(3)
        ->and(($this->stamps)())->toBe(0);
});

it('sends the owner back to the list once every stamp given here is taken back', function (): void {
    // At once, not once a queue worker gets to the summary.
    Queue::fake();
    $this->context->bypass(fn () => CardEnrollment::query()->whereKey($this->enrollment->id)->update(['current_stamps' => 1]));

    // No visit left here: no longer the business's customer.
    ($this->send)('customers.corrections.store')->assertRedirect(route('customers.index'));

    expect($this->context->bypass(fn (): int => BusinessCustomer::query()->count()))->toBe(0);
});

it('refuses a key that is no UUID and a reason of only invisible characters, before stamping', function (array $input, string $field): void {
    ($this->send)('customers.stamps.store', $input)->assertSessionHasErrors($field);

    expect(($this->ledger)())->toHaveCount(1);
})->with([
    'a long key of accented letters' => [['key' => str_repeat('é', 64)], 'key'],
    'a reason of control characters' => [['reason' => "\0\0"], 'reason'],
    'a reason of invisible marks only' => [['reason' => "\u{2066}\u{2069}"], 'reason'],
]);

it('never lets the org admin of the network, or another organization, stamp or keep notes on the customers of a business', function (): void {
    // The org admin works across the network, never in one business: no customer is found there.
    $admin = $this->tenants->admin(User::factory()->create(), $this->tenants->orgA);
    $other = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);

    $this->actingAs($admin)->withSession([SetTenant::SESSION_KEY => SetTenant::organizationChoice($this->tenants->orgA->id)])
        ->post(route('customers.stamps.store', $this->customer->id), [])->assertNotFound();
    ($this->as)($other, $this->tenants->b1)->post(route('customers.stamps.store', $this->customer->id), [])->assertNotFound();
    ($this->as)($other, $this->tenants->b1)->delete(route('customers.notes.destroy', [$this->customer->id, 1]))->assertNotFound();

    expect(($this->ledger)())->toHaveCount(1);
});

it('still takes back stamps given here on a card switched off', function (): void {
    $this->context->bypass(fn () => CardEnrollment::query()->whereKey($this->enrollment->id)->update(['current_stamps' => 1]));
    $this->context->bypass(fn () => LoyaltyCard::query()->whereKey($this->tenants->cardA->id)->update(['active' => false]));

    ($this->send)('customers.stamps.store')->assertSessionHasErrors(['enrollment' => __('This card isn’t running here: nothing was added.')]);
    ($this->send)('customers.corrections.store')->assertSessionHasNoErrors();

    expect(($this->stamps)())->toBe(0);
});

it('says when a cooldown of a day or more ends: tomorrow, or on its day', function (int $minutes, string $message, string $format): void {
    $this->context->bypass(fn () => LoyaltyCard::query()->whereKey($this->tenants->cardA->id)->update(['cooldown_min' => $minutes]));
    $this->tenants->stamp($this->enrollment, $this->tenants->a1, ['created_at' => now()]);
    $next = now()->addMinutes($minutes)->setTimezone($this->site->timezone);

    ($this->send)('customers.stamps.store')->assertSessionHasErrors(['qty' => __($message, [
        'time' => $next->format('H:i'),
        'date' => $next->translatedFormat($format),
    ])]);
})->with([
    'tomorrow' => [24 * 60, 'Too soon after their last stamp on this card: the next one can be given tomorrow from :time.', 'l j F'],
    'a later day' => [3 * 24 * 60, 'Too soon after their last stamp on this card: the next one can be given on :date from :time.', 'l j F'],
]);

it('takes back stamps at a closed site, and only adds them at an open one', function (): void {
    $this->context->bypass(fn () => CardEnrollment::query()->whereKey($this->enrollment->id)->update(['current_stamps' => 1]));
    $this->tenants->stamp($this->enrollment, $this->tenants->a1, ['created_at' => now()->subDays(2)]);
    $this->context->bypass(fn () => $this->site->forceFill(['archived_at' => now()])->save());

    ($this->as)($this->owner)->get(route('customers.show', $this->customer->id))->assertInertia(fn (Assert $page): Assert => $page
        ->where('locations', [])
        ->where('correctionLocations', [['id' => $this->site->id, 'name' => $this->site->name]]));
    ($this->send)('customers.stamps.store')->assertSessionHasErrors('location');
    ($this->send)('customers.corrections.store')->assertSessionHasNoErrors();

    expect(($this->stamps)())->toBe(0);
});

it('shows every note, past the first fifty', function (): void {
    $this->context->bypass(fn () => collect(range(1, 51))->each(fn (int $n) => CustomerNote::query()->create([
        'business_id' => $this->tenants->a1->id, 'user_id' => $this->user->id, 'body' => 'Note '.$n,
    ])));

    ($this->as)($this->owner)->get(route('customers.show', $this->customer->id))->assertInertia(fn (Assert $page): Assert => $page
        ->has('notes', 51)
        ->where('notes.50.body', 'Note 1'));
});
