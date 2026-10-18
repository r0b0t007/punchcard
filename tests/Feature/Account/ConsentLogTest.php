<?php

declare(strict_types=1);

use App\Actions\Auth\RecordConsent;
use App\Models\ConsentEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| The consent log (CHW-58)
|--------------------------------------------------------------------------
|
| Every change of a customer's consent, append-only: punchcard's news and
| each program's offers (follows), with where it came from and when, so the
| consent behind a message sent on a given day stays known. Consents given
| before the log are written into it when it is created.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-18 10:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = User::factory()->create(['marketing_opt_in_at' => null]);
    $this->enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->follow = fn (bool $follow) => $this->actingAs($this->customer)->patch(route('app.cards.follow', $this->enrollment->id), ['follow' => $follow]);
    $this->log = fn (): array => ConsentEvent::query()->where('user_id', $this->customer->id)->orderBy('id')->get()
        ->map(fn (ConsentEvent $event): array => [$event->kind, $event->organization_id, $event->granted, $event->source, $event->created_at->toDateTimeString()])->all();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('logs each real change of a follow, from the card\'s page', function (): void {
    ($this->follow)(true);
    ($this->follow)(true);
    Carbon::setTestNow('2026-10-20 09:00:00');
    ($this->follow)(false);
    ($this->follow)(false);

    $org = $this->tenants->orgA->id;
    expect(($this->log)())->toBe([
        ['program_offers', $org, true, 'card', '2026-10-18 10:00:00'],
        ['program_offers', $org, false, 'card', '2026-10-20 09:00:00'],
    ]);
});

it('logs nothing for a follow refused, or turned off when never on', function (): void {
    ($this->follow)(false);
    $unverified = User::factory()->unverified()->create();
    $theirs = $this->tenants->enroll($unverified, $this->tenants->cardA);
    $this->actingAs($unverified)->patch(route('app.cards.follow', $theirs->id), ['follow' => true])->assertSessionHasErrors('follow');

    expect(ConsentEvent::query()->count())->toBe(0);
});

it('records a first "no" from someone who never chose, which a later form then respects', function (): void {
    $this->actingAs($this->customer)->patch(route('privacy.marketing'), ['marketing' => false])->assertSessionHasNoErrors();

    app(RecordConsent::class)->handle($this->customer->fresh(), wantsNews: true);

    expect($this->customer->fresh())
        ->marketing_opt_in_at->toBeNull()
        ->marketing_opt_out_at->toEqual(now())
        ->and(($this->log)())->toBe([['punchcard_news', null, false, 'settings', '2026-10-18 10:00:00']]);
});

it('records consent on the account it was given, and nothing for one deleted meanwhile', function (): void {
    $given = $this->customer->fresh();

    expect(app(RecordConsent::class)->handle($given, wantsNews: true))->toBe($given)
        ->and($given->marketing_opt_in_at)->toEqual(now());

    $gone = User::factory()->create(['marketing_opt_in_at' => null]);
    DB::table('users')->where('id', $gone->id)->delete();

    expect(app(RecordConsent::class)->handle($gone, wantsNews: true))->toBe($gone)
        ->and(ConsentEvent::query()->where('user_id', $gone->id)->exists())->toBeFalse();
});

it('refuses any rewrite of the log in the database too', function (): void {
    ConsentEvent::factory()->create(['user_id' => $this->customer->id]);

    // In its own savepoint: on Postgres a refused statement aborts the transaction around it.
    expect(fn () => DB::transaction(fn () => DB::table('consent_events')->where('user_id', $this->customer->id)->update(['granted' => false])))->toThrow(QueryException::class)
        ->and(ConsentEvent::query()->where('user_id', $this->customer->id)->sole()->granted)->toBeTrue();
});

it('writes the consents given before the log into it', function (): void {
    $news = User::factory()->create(['marketing_opt_in_at' => '2026-10-10 08:00:00']);
    $this->context->bypass(function (): void {
        DB::table('follows')->insert([
            ['user_id' => $this->customer->id, 'organization_id' => $this->tenants->orgA->id, 'notify' => true, 'created_at' => '2026-10-11 08:00:00', 'updated_at' => '2026-10-11 08:00:00'],
            ['user_id' => $this->customer->id, 'organization_id' => $this->tenants->orgB->id, 'notify' => false, 'created_at' => '2026-10-12 08:00:00', 'updated_at' => '2026-10-14 08:00:00'],
        ]);
    });

    $migration = require database_path('migrations/2026_10_18_151043_create_consent_events_table.php');
    $migration->backfill();

    expect(ConsentEvent::query()->where('user_id', $news->id)->get(['kind', 'granted', 'source', 'created_at'])->map(fn (ConsentEvent $event): array => [$event->kind, $event->granted, $event->source, $event->created_at->toDateTimeString()])->all())
        ->toBe([['punchcard_news', true, 'sign_up', '2026-10-10 08:00:00']])
        ->and(($this->log)())->toBe([
            ['program_offers', $this->tenants->orgA->id, true, 'card', '2026-10-11 08:00:00'],
            ['program_offers', $this->tenants->orgB->id, true, 'card', '2026-10-12 08:00:00'],
            ['program_offers', $this->tenants->orgB->id, false, 'card', '2026-10-14 08:00:00'],
        ]);
});
