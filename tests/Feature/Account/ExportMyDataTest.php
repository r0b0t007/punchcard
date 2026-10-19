<?php

declare(strict_types=1);

use App\Enums\BusinessRole;
use App\Enums\RewardStatus;
use App\Enums\StampSource;
use App\Enums\TapStatus;
use App\Models\ConsentEvent;
use App\Models\Follow;
use App\Models\Tap;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Download my data (CHW-58, C12)
|--------------------------------------------------------------------------
|
| Everything punchcard keeps about the customer, in one JSON file: the
| account, consents, each card with its stamps and rewards, follows, recent
| taps, passkeys, sessions and memberships. Only after confirming it's them
| in the last 15 minutes, at most 5 times an hour. Never another customer's
| data, never a staff member's name, never a secret.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-18 10:00:00');
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = User::factory()->create(['name' => 'Salma Tazi', 'email' => 'salma@example.com', 'locale' => 'fr']);
    $this->atA = $this->tenants->enroll($this->customer, $this->tenants->cardA);
    $this->atB = $this->tenants->enroll($this->customer, $this->tenants->cardB);
    $this->download = fn (?User $as = null, ?int $confirmedAt = null): TestResponse => $this->actingAs($as ?? $this->customer)
        ->withSession(['auth.password_confirmed_at' => $confirmedAt ?? now()->unix()])
        ->post(route('privacy.export'));
    $this->data = fn (TestResponse $response): array => json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('needs a confirmation within the last 15 minutes, else goes back to the page to confirm first', function (): void {
    $this->actingAs($this->customer)->post(route('privacy.export'))->assertRedirect(route('privacy.edit'));
    ($this->download)(confirmedAt: now()->subMinutes(16)->unix())->assertRedirect(route('privacy.edit'));
    ($this->download)(confirmedAt: now()->subMinutes(14)->unix())->assertOk();
});

it('confirms it\'s you from the page, then offers the file there', function (): void {
    $this->actingAs($this->customer)->get(route('privacy.confirm'))->assertRedirect(route('password.confirm'));
    $this->actingAs($this->customer)->get(route('privacy.edit'))->assertInertia(fn (Assert $page): Assert => $page->where('download.ready', false));

    $this->actingAs($this->customer)->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->get(route('privacy.confirm'))->assertRedirect(route('privacy.edit'));
    $this->actingAs($this->customer)->withSession(['auth.password_confirmed_at' => now()->subMinutes(14)->unix()])
        ->get(route('privacy.edit'))->assertInertia(fn (Assert $page): Assert => $page->where('download.ready', true));
    $this->actingAs($this->customer)->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->unix()])
        ->get(route('privacy.edit'))->assertInertia(fn (Assert $page): Assert => $page->where('download.ready', false));
});

it('downloads a JSON file named for the day, as a file to save, never cached', function (): void {
    $response = ($this->download)()->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'attachment; filename="punchcard-data-2026-10-18.json"');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(($this->data)($response))->toMatchArray(['format_version' => 1, 'generated_at' => '2026-10-18T10:00:00+00:00']);
});

it('holds the account, its consents and follows', function (): void {
    $this->customer->forceFill(['marketing_opt_in_at' => now()->subDay(), 'adult_confirmed_at' => now()->subWeek()])->save();
    ConsentEvent::record($this->customer->id, ConsentEvent::KIND_PUNCHCARD_NEWS, true, ConsentEvent::SOURCE_SIGN_UP);
    $this->actingAs($this->customer)->patch(route('privacy.follow', $this->atB->id), ['follow' => true]);

    $data = ($this->data)(($this->download)());

    expect($data['account'])->toBe([
        'name' => 'Salma Tazi', 'email' => 'salma@example.com', 'email_verified_at' => $this->customer->email_verified_at?->toIso8601String(),
        'pending_email' => null, 'locale' => 'fr', 'created_at' => '2026-10-18T10:00:00+00:00', 'adult_confirmed_at' => '2026-10-11T10:00:00+00:00',
        'two_factor_enabled' => false,
    ])
        ->and($data['consents']['punchcard_news'])->toBe(['on' => true, 'on_since' => '2026-10-17T10:00:00+00:00', 'off_since' => null])
        ->and($data['consents']['log'])->toBe([
            ['kind' => 'punchcard_news', 'organization' => null, 'granted' => true, 'source' => 'sign_up', 'at' => '2026-10-18T10:00:00+00:00'],
            ['kind' => 'program_offers', 'organization' => $this->tenants->orgB->name, 'granted' => true, 'source' => 'settings', 'at' => '2026-10-18T10:00:00+00:00'],
        ])
        ->and($data['follows'])->toBe([
            ['organization' => $this->tenants->orgB->name, 'following' => true, 'since' => '2026-10-18T10:00:00+00:00', 'changed_at' => '2026-10-18T10:00:00+00:00'],
        ]);
});

it('holds every card across organizations, with each stamp and reward, and who gave it without naming staff', function (): void {
    $staff = User::factory()->create(['name' => 'Karim Staffname', 'email' => 'karim@staff.test']);
    $stamper = $this->tenants->stamper($this->tenants->a1);
    $this->tenants->stamp($this->atA, $this->tenants->a1, ['source' => StampSource::Nfc, 'stamper_id' => $stamper->id, 'nfc_tag_id' => $stamper->nfc_tag_id, 'counter' => 7]);
    $this->tenants->stamp($this->atA, $this->tenants->a2, ['source' => StampSource::Manual, 'qty' => 2, 'staff_id' => $staff->id, 'reason' => 'Forgot my phone']);
    $this->tenants->stamp($this->atA, $this->tenants->a2, ['source' => StampSource::Correction, 'qty' => -1, 'staff_id' => $staff->id, 'reason' => 'Double stamp']);
    $this->tenants->stamp($this->atB, $this->tenants->b1, ['source' => StampSource::Bonus, 'idempotency_key' => 'bonus-1']);
    $reward = $this->tenants->reward($this->atA);
    $this->context->bypass(fn () => $reward->forceFill(['status' => RewardStatus::Redeemed, 'redeemed_at' => now(), 'redeemed_by' => $staff->id, 'redeemed_business_id' => $this->tenants->a1->id])->save());

    $response = ($this->download)();
    $cards = collect(($this->data)($response)['cards'])->keyBy('card');
    $a = $cards->get('A card');

    expect($cards->keys()->sort()->values()->all())->toBe(['A card', 'B card'])
        ->and($a['organization'])->toBe($this->tenants->orgA->name)
        ->and(array_map(fn (array $stamp): array => [$stamp['business'], $stamp['location'], $stamp['qty'], $stamp['source'], $stamp['by'], $stamp['reason']], $a['stamps']))->toBe([
            ['A1', 'A1 site', 1, 'nfc', 'you', null],
            ['A2', 'A2 site', 2, 'manual', 'staff', 'Forgot my phone'],
            ['A2', 'A2 site', -1, 'correction', 'staff', 'Double stamp'],
        ])
        ->and($cards->get('B card')['stamps'][0]['by'])->toBe('punchcard')
        ->and($a['rewards'])->toHaveCount(1)
        ->and($a['rewards'][0])->toMatchArray(['status' => 'redeemed', 'redeemed_at' => '2026-10-18T10:00:00+00:00', 'redeemed_business' => 'A1'])
        ->and($response->getContent())->not->toContain('Karim')->not->toContain('karim@staff.test')->not->toContain('"staff_id"');
});

it('never holds another customer\'s data, even at the same place', function (): void {
    $other = User::factory()->create(['name' => 'Other Person', 'email' => 'other@example.com']);
    $theirs = $this->tenants->enroll($other, $this->tenants->cardA);
    $this->tenants->stamp($theirs, $this->tenants->a1, ['source' => StampSource::Manual, 'reason' => 'their-secret-reason']);
    $this->tenants->reward($theirs);
    $this->context->bypass(function () use ($other): void {
        Follow::query()->create(['user_id' => $other->id, 'organization_id' => $this->tenants->orgA->id, 'notify' => true]);
        (new Tap)->forceFill(['user_id' => $other->id, 'business_id' => $this->tenants->a1->id, 'status' => TapStatus::Stamped, 'ip' => '198.51.100.9', 'user_agent' => 'Their phone'])->save();
    });
    ConsentEvent::record($other->id, ConsentEvent::KIND_PROGRAM_OFFERS, true, ConsentEvent::SOURCE_CARD, $this->tenants->orgA->id);
    $this->tenants->member($other, $this->tenants->a1);
    DB::table('sessions')->insert(['id' => 'their-session', 'user_id' => $other->id, 'ip_address' => '198.51.100.10', 'user_agent' => 'Their laptop', 'payload' => '', 'last_activity' => now()->unix()]);

    $response = ($this->download)();
    $data = ($this->data)($response);

    expect($data['cards'])->toHaveCount(2)
        ->and(collect($data['cards'])->flatMap(fn (array $card): array => $card['rewards']))->toHaveCount(0)
        ->and($data['follows'])->toBe([])
        ->and($data['taps'])->toBe([])
        ->and($data['consents']['log'])->toBe([])
        ->and($data['sessions'])->toBe([])
        ->and($data['memberships'])->toBe([])
        ->and($response->getContent())->not->toContain('Other Person')->not->toContain('other@example.com')
        ->not->toContain('their-secret-reason')->not->toContain('198.51.100.9');
});

it('holds recent taps, passkeys and sessions, and never a secret', function (): void {
    $this->customer->forceFill(['password' => 'secret-password', 'two_factor_secret' => 'TOTP-SECRET-VALUE', 'remember_token' => 'remember-me-token'])->save();
    $this->customer->passkeys()->create(['name' => 'My iPhone', 'credential_id' => 'credential-id-value', 'credential' => ['publicKey' => 'public-key-value']]);
    $stamper = $this->tenants->stamper($this->tenants->a1);
    $this->context->bypass(function () use ($stamper): void {
        (new Tap)->forceFill(['user_id' => $this->customer->id, 'nfc_tag_id' => $stamper->nfc_tag_id, 'stamper_id' => $stamper->id, 'business_id' => $this->tenants->a1->id,
            'location_id' => $stamper->location_id, 'counter' => 4242, 'status' => TapStatus::Stamped, 'ip' => '203.0.113.7', 'user_agent' => 'My phone',
            'claim_token_hash' => hash('sha256', 'a-session-token')])->save();
        (new Tap)->forceFill(['user_id' => $this->customer->id, 'business_id' => $this->tenants->a1->id, 'status' => TapStatus::Stamped, 'ip' => '203.0.113.99',
            'created_at' => now()->subDays(181)])->save();
    });
    DB::table('sessions')->insert(['id' => 'session-id-value', 'user_id' => $this->customer->id, 'ip_address' => '203.0.113.8', 'user_agent' => 'My laptop',
        'payload' => 'session-payload-value', 'last_activity' => now()->unix()]);

    $response = ($this->download)();
    $data = ($this->data)($response);

    expect($data['taps'])->toBe([['at' => '2026-10-18T10:00:00+00:00', 'business' => 'A1', 'location' => 'A1 site', 'status' => 'stamped', 'ip' => '203.0.113.7', 'user_agent' => 'My phone']])
        ->and($data['passkeys'])->toBe([['name' => 'My iPhone', 'created_at' => '2026-10-18T10:00:00+00:00', 'last_used_at' => null]])
        ->and($data['sessions'])->toBe([['ip' => '203.0.113.8', 'user_agent' => 'My laptop', 'last_active_at' => '2026-10-18T10:00:00+00:00']]);

    foreach (['secret-password', $this->customer->password, 'TOTP-SECRET-VALUE', 'remember-me-token', 'credential-id-value', 'public-key-value',
        'session-id-value', 'session-payload-value', hash('sha256', 'a-session-token'), '4242', '203.0.113.99'] as $secret) {
        expect($response->getContent())->not->toContain((string) $secret);
    }
});

it('holds an owner\'s own cards and their roles', function (): void {
    $owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);
    $this->tenants->admin($owner, $this->tenants->orgA);
    $this->tenants->enroll($owner, $this->tenants->cardB);

    $data = ($this->data)(($this->download)($owner));

    expect(array_column($data['cards'], 'card'))->toBe(['B card'])
        ->and($data['memberships'])->toBe([
            ['organization' => $this->tenants->orgA->name, 'business' => 'A1', 'role' => 'owner', 'since' => '2026-10-18T10:00:00+00:00'],
            ['organization' => $this->tenants->orgA->name, 'business' => null, 'role' => 'org_admin', 'since' => '2026-10-18T10:00:00+00:00'],
        ]);
});

it('allows 5 downloads an hour, then says so on the page', function (): void {
    foreach (range(1, 5) as $attempt) {
        ($this->download)()->assertOk();
    }

    ($this->download)()->assertRedirect(route('privacy.edit'))->assertSessionHas('status', 'export-limited');
    $this->actingAs($this->customer)->withSession(['status' => 'export-limited'])->get(route('privacy.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('status', 'export-limited'));

    Carbon::setTestNow(now()->addHour()->addSecond());
    ($this->download)()->assertOk();
});
