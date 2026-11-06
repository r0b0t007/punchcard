<?php

declare(strict_types=1);

use App\Enums\AutomationTrigger;
use App\Enums\BusinessRole;
use App\Features\Automations;
use App\Http\Middleware\SetTenant;
use App\Models\Automation;
use App\Models\AutomationSend;
use App\Models\Business;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The Automations page (CHW-46, B11, design 18a)
|--------------------------------------------------------------------------
|
| Whoever runs a business allowed automations sees its three, in the cap's
| order, each as saved or as it starts (off, our message), with its message
| as a customer gets it and how many people it reached in 30 days; turns
| each on or off, sets its one setting and writes its own message. Never
| staff, never a business not allowed them, never another business's.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-04 10:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    Feature::for($this->tenants->a1)->activate(Automations::class);
    $this->owner = $this->tenants->member(User::factory()->create(['locale' => 'fr']), $this->tenants->a1, BusinessRole::Owner);
    $this->as = fn (User $user, ?Business $business = null) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.($business ?? $this->tenants->a1)->id]);
    $this->saved = fn (AutomationTrigger $trigger, ?Business $business = null): ?Automation => $this->context->bypass(fn (): ?Automation => Automation::query()
        ->where('business_id', ($business ?? $this->tenants->a1)->id)->where('trigger', $trigger)->first());
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the owner the three automations as they start, with our message in their language', function (): void {
    ($this->as)($this->owner)->get(route('automations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('business/automations')
            ->where('business', 'A1')
            ->where('automations.0', [
                'trigger' => 'reward_reminder', 'name' => 'Rappel de récompense', 'active' => false, 'days' => 7, 'stamps' => 1, 'title' => null, 'body' => null,
                'ours' => ['title' => 'Votre récompense vous attend chez A1', 'body' => 'N\'oubliez pas : Free coffee est à vous.'], 'sent' => 0,
            ])
            ->where('automations.1.trigger', 'birthday')->where('automations.1.ours.body', '1 tampon offert sur votre carte.')
            ->where('automations.2.trigger', 'win_back')->where('automations.2.ours.title', 'Vous nous manquez chez A1')
            ->where('limits', ['days' => ['min' => 1, 'max' => 60], 'stamps' => ['min' => 1, 'max' => 3], 'title' => 35, 'body' => 120])
            ->where('nav.automations', true));
});

it('counts the people each reached in 30 days, a phone having accepted it', function (): void {
    $automation = $this->context->bypass(fn (): Automation => Automation::factory()->create(['business_id' => $this->tenants->a1->id, 'trigger' => AutomationTrigger::WinBack]));
    $send = fn (?string $sentAt, string $occasion) => $this->context->bypass(fn () => AutomationSend::factory()->create(['automation_id' => $automation->id, 'occasion' => $occasion])
        ->markSent($sentAt === null ? now() : Carbon::parse($sentAt)));
    $send(null, 'quiet:2026-11-01');
    $send('2026-10-20 10:00:00', 'quiet:2026-10-20');
    $send('2026-09-01 10:00:00', 'quiet:2026-09-01');
    // Claimed, never accepted by a phone.
    $this->context->bypass(fn () => AutomationSend::factory()->create(['automation_id' => $automation->id, 'occasion' => 'quiet:2026-11-03']));

    ($this->as)($this->owner)->get(route('automations.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('automations.2.active', true)->where('automations.2.sent', 2));
});

it('turns an automation on with its setting, then off, saying so', function (string $trigger, array $setting, string $field, int $value): void {
    ($this->as)($this->owner)->patch(route('automations.update', $trigger), ['active' => true, ...$setting, 'title' => '', 'body' => ''])
        ->assertRedirect(route('automations.index'))
        ->assertInertiaFlash('toast.message', ($trigger === 'birthday' ? 'Bonus d’anniversaire' : 'Rappel de récompense').' est activé.');

    $saved = ($this->saved)(AutomationTrigger::from($trigger));

    expect($saved?->active)->toBeTrue()
        ->and($saved?->{$field})->toBe($value)
        ->and($saved?->title)->toBeNull()
        ->and($saved?->body)->toBeNull();

    ($this->as)($this->owner)->patch(route('automations.update', $trigger), ['active' => false, ...$setting, 'title' => null, 'body' => null])
        ->assertInertiaFlash('toast.message', ($trigger === 'birthday' ? 'Bonus d’anniversaire' : 'Rappel de récompense').' est désactivé.');

    expect(($this->saved)(AutomationTrigger::from($trigger))?->active)->toBeFalse();
})->with([
    'the reward reminder, after 10 days' => ['reward_reminder', ['days' => 10], 'days', 10],
    'the birthday, with 3 stamps' => ['birthday', ['stamps' => 3], 'stamps', 3],
]);

it('keeps the owner\'s own message, trimmed, and goes back to ours', function (): void {
    ($this->as)($this->owner)->patch(route('automations.update', 'win_back'), ['active' => true, 'title' => '  On vous attend ', 'body' => 'Deux tampons sur votre prochain café.'])
        ->assertInertiaFlash('toast.message', 'Relance est activé.');

    expect(($this->saved)(AutomationTrigger::WinBack)?->only(['title', 'body']))->toBe(['title' => 'On vous attend', 'body' => 'Deux tampons sur votre prochain café.']);

    ($this->as)($this->owner)->patch(route('automations.update', 'win_back'), ['active' => true, 'title' => null, 'body' => null])
        ->assertInertiaFlash('toast.message', 'Enregistré.');

    expect(($this->saved)(AutomationTrigger::WinBack)?->only(['title', 'body']))->toBe(['title' => null, 'body' => null]);
});

it('refuses a setting out of range, one the automation does not have, or a message too long', function (string $trigger, array $values, string $error): void {
    $request = array_filter(['active' => true, 'title' => null, 'body' => null, ...$values], fn (mixed $value): bool => $value !== 'unset');

    ($this->as)($this->owner)->from(route('automations.index'))->patch(route('automations.update', $trigger), $request)
        ->assertSessionHasErrors($error);

    expect(($this->saved)(AutomationTrigger::from($trigger)))->toBeNull();
})->with([
    'no wait' => ['reward_reminder', ['days' => 0], 'days'],
    'a wait past 60 days' => ['reward_reminder', ['days' => 61], 'days'],
    'no bonus' => ['birthday', ['stamps' => 0], 'stamps'],
    'a bonus past 3' => ['birthday', ['stamps' => 4], 'stamps'],
    'a wait on the birthday' => ['birthday', ['stamps' => 1, 'days' => 5], 'days'],
    'no wait given' => ['reward_reminder', [], 'days'],
    'a title with no room beside the name' => ['win_back', ['title' => str_repeat('a', 36)], 'title'],
    'a message past 120' => ['win_back', ['body' => str_repeat('a', 121)], 'body'],
    'stamps on the reminder' => ['reward_reminder', ['days' => 7, 'stamps' => 2], 'stamps'],
    'stamps on the win-back' => ['win_back', ['stamps' => 2], 'stamps'],
    'a wait on the win-back' => ['win_back', ['days' => 7], 'days'],
    'no on or off' => ['win_back', ['active' => null], 'active'],
    'no title sent' => ['win_back', ['title' => 'unset'], 'title'],
]);

it('is never staff\'s, nor a business\'s not allowed automations, nor offered to either', function (string $who): void {
    $user = $this->owner;

    if ($who === 'staff') {
        $user = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Staff);
    } else {
        Feature::for($this->tenants->a1)->deactivate(Automations::class);
    }

    ($this->as)($user)->get(route('automations.index'))->assertForbidden();
    ($this->as)($user)->patch(route('automations.update', 'win_back'), ['active' => true])->assertForbidden();
    // Staff land on counter mode from the dashboard: wherever they land, no Automations in the sidebar.
    ($this->as)($user)->followingRedirects()->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page->where('nav.automations', false));

    expect(($this->saved)(AutomationTrigger::WinBack))->toBeNull();
})->with(['staff', 'not allowed']);

it('keeps each franchisee to its own automations', function (): void {
    Feature::for($this->tenants->a2)->activate(Automations::class);
    $this->context->bypass(fn (): Automation => Automation::factory()->create(['business_id' => $this->tenants->a1->id, 'trigger' => AutomationTrigger::WinBack, 'title' => 'A1’s own']));
    $a2s = $this->tenants->member(User::factory()->create(), $this->tenants->a2, BusinessRole::Owner);

    ($this->as)($a2s, $this->tenants->a2)->get(route('automations.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('business', 'A2')->where('automations.2.active', false)->where('automations.2.title', null));
    ($this->as)($a2s, $this->tenants->a2)->patch(route('automations.update', 'win_back'), ['active' => true, 'title' => 'A2’s own', 'body' => null]);

    expect(($this->saved)(AutomationTrigger::WinBack)?->title)->toBe('A1’s own')
        ->and(($this->saved)(AutomationTrigger::WinBack, $this->tenants->a2)?->title)->toBe('A2’s own');
});

it('keeps organization B to its own automations and counts, and A to its own', function (): void {
    Feature::for($this->tenants->b1)->activate(Automations::class);
    $a1s = $this->context->bypass(fn (): Automation => Automation::factory()->create(['business_id' => $this->tenants->a1->id, 'trigger' => AutomationTrigger::WinBack]));
    $this->context->bypass(fn () => AutomationSend::factory()->create(['automation_id' => $a1s->id])->markSent(now()));
    $b1sOwner = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);

    ($this->as)($b1sOwner, $this->tenants->b1)->get(route('automations.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('business', 'B1')->where('automations.2.active', false)->where('automations.2.sent', 0));
    ($this->as)($b1sOwner, $this->tenants->b1)->patch(route('automations.update', 'win_back'), ['active' => true, 'title' => 'B1’s own', 'body' => null]);
    ($this->as)($this->owner)->get(route('automations.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('automations.2.sent', 1)->where('automations.2.title', null));

    expect(($this->saved)(AutomationTrigger::WinBack, $this->tenants->b1)?->title)->toBe('B1’s own')
        ->and(($this->saved)(AutomationTrigger::WinBack)?->title)->toBeNull();
});

it('counts a sister franchisee\'s sends to its own automation only', function (): void {
    Feature::for($this->tenants->a2)->activate(Automations::class);
    $a2s = $this->context->bypass(fn (): Automation => Automation::factory()->create(['business_id' => $this->tenants->a2->id, 'trigger' => AutomationTrigger::WinBack]));
    $this->context->bypass(fn () => AutomationSend::factory()->create(['automation_id' => $a2s->id])->markSent(now()));
    $this->context->bypass(fn (): Automation => Automation::factory()->create(['business_id' => $this->tenants->a1->id, 'trigger' => AutomationTrigger::WinBack]));

    ($this->as)($this->owner)->get(route('automations.index'))->assertInertia(fn (Assert $page): Assert => $page->where('automations.2.sent', 0));
});

it('counts people, not messages: a customer reminded of two rewards is one', function (): void {
    $automation = $this->context->bypass(fn (): Automation => Automation::factory()->create(['business_id' => $this->tenants->a1->id, 'trigger' => AutomationTrigger::RewardReminder]));
    $customer = User::factory()->create();
    foreach (['reward:1', 'reward:2'] as $occasion) {
        $this->context->bypass(fn () => AutomationSend::factory()->create(['automation_id' => $automation->id, 'user_id' => $customer->id, 'occasion' => $occasion])->markSent(now()));
    }

    ($this->as)($this->owner)->get(route('automations.index'))->assertInertia(fn (Assert $page): Assert => $page->where('automations.0.sent', 1));
});

it('asks each business\'s own flag: A1 allowed never lets A2 in', function (): void {
    $a2s = $this->tenants->member(User::factory()->create(), $this->tenants->a2, BusinessRole::Owner);

    ($this->as)($a2s, $this->tenants->a2)->get(route('automations.index'))->assertForbidden();
    ($this->as)($a2s, $this->tenants->a2)->patch(route('automations.update', 'win_back'), ['active' => true, 'title' => null, 'body' => null])->assertForbidden();

    expect(($this->saved)(AutomationTrigger::WinBack, $this->tenants->a2))->toBeNull();
});

it('lets the org admin set a business\'s automations inside it, never across the organization', function (): void {
    // The HQ's org admin, on A1's team too: inside A1 their org admin rights count, whatever their role there.
    $admin = $this->tenants->member($this->tenants->admin(User::factory()->create(), $this->tenants->orgA), $this->tenants->a1, BusinessRole::Staff);
    $across = fn () => $this->actingAs($admin)->withSession([SetTenant::SESSION_KEY => 'org:'.$this->tenants->orgA->id]);

    $across()->get(route('automations.index'))->assertForbidden();
    $across()->patch(route('automations.update', 'win_back'), ['active' => true, 'title' => null, 'body' => null])->assertForbidden();
    $across()->get(route('dashboard'))->assertInertia(fn (Assert $page): Assert => $page->where('nav.automations', false));

    ($this->as)($admin)->patch(route('automations.update', 'win_back'), ['active' => true, 'title' => null, 'body' => null])->assertRedirect(route('automations.index'));

    expect(($this->saved)(AutomationTrigger::WinBack)?->active)->toBeTrue();
});

it('makes an automation once when two first saves meet', function (): void {
    ($this->as)($this->owner)->patch(route('automations.update', 'birthday'), ['active' => true, 'stamps' => 2, 'title' => null, 'body' => null]);
    ($this->as)($this->owner)->patch(route('automations.update', 'birthday'), ['active' => true, 'stamps' => 3, 'title' => null, 'body' => null])
        ->assertInertiaFlash('toast.message', 'Enregistré.');

    expect($this->context->bypass(fn (): int => Automation::query()->where('business_id', $this->tenants->a1->id)->count()))->toBe(1)
        ->and(($this->saved)(AutomationTrigger::Birthday)?->stamps)->toBe(3);
});
