<?php

declare(strict_types=1);

use App\Actions\Taps\ReceiveTap;
use App\Enums\StamperStatus;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Models\Tap;
use App\Models\User;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\SunVectors;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Receiving a tap (CHW-25)
|--------------------------------------------------------------------------
|
| ReceiveTap verifies the SUN URL, then, in one committed step under the tag
| lock: requires a counter above the tag's last one and spends it, finds the
| current stamper, reads and clears its arming, and records the tap. Refused
| taps are recorded too, so a refused URL is spent like a stamped one.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 10:00:00');
    config(['punchcard.nfc.sun_master_key' => SunVectors::AN10922_MASTER_KEY, 'punchcard.nfc.key_version' => 1]);
    app()->forgetInstance(KeyDiversifier::class);

    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->stamper = $this->tenants->stamper($this->tenants->a1);
    $this->tag = $this->context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($this->stamper->nfc_tag_id));
    $this->customer = User::factory()->create();

    $this->url = fn (int $counter, ?NfcTag $tag = null, int $keyVersion = 1): array => app(FakeTap::class)->build(($tag ?? $this->tag)->uid, $counter, $keyVersion);
    $this->receive = fn (array $url, ?User $user = null): Tap => app(ReceiveTap::class)->handle($url['e'], $url['c'], $user, '203.0.113.7', 'Test phone');
    $this->lastCounter = fn (): int => $this->context->bypass(fn (): int => (int) NfcTag::query()->whereKey($this->tag->id)->value('last_counter'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('records a verified tap as pending and spends its counter', function (): void {
    $tap = ($this->receive)(($this->url)(5), $this->customer);

    expect($tap->only(['status', 'rejection', 'nfc_tag_id', 'stamper_id', 'business_id', 'location_id', 'counter', 'qty', 'user_id', 'ip', 'user_agent']))->toBe([
        'status' => TapStatus::Pending,
        'rejection' => null,
        'nfc_tag_id' => $this->tag->id,
        'stamper_id' => $this->stamper->id,
        'business_id' => $this->tenants->a1->id,
        'location_id' => $this->tenants->locationOf($this->tenants->a1)->id,
        'counter' => 5,
        'qty' => 1,
        'user_id' => $this->customer->id,
        'ip' => '203.0.113.7',
        'user_agent' => 'Test phone',
    ])
        ->and($tap->expires_at?->toDateTimeString())->toBe('2026-10-05 10:30:00')
        ->and(($this->lastCounter)())->toBe(5);
});

it('refuses a replayed or older counter', function (int $counter): void {
    ($this->receive)(($this->url)(5));

    $replay = ($this->receive)(($this->url)($counter));

    expect($replay->status)->toBe(TapStatus::Rejected)
        ->and($replay->rejection)->toBe(TapRejection::Replay)
        ->and($replay->counter)->toBeNull()
        ->and($replay->only(['stamper_id', 'business_id']))->toBe(['stamper_id' => $this->stamper->id, 'business_id' => $this->tenants->a1->id])
        ->and(($this->lastCounter)())->toBe(5);
})->with(['the same counter' => [5], 'an older counter' => [4]]);

it('refuses a tampered or malformed URL without trusting its tag or counter', function (string $change, TapRejection $rejection): void {
    $url = ($this->url)(5);
    $url = match ($change) {
        'a tampered MAC' => ['e' => $url['e'], 'c' => strrev($url['c'])],
        'a malformed e' => ['e' => 'not-hex', 'c' => $url['c']],
        'a short c' => ['e' => $url['e'], 'c' => substr($url['c'], 2)],
    };

    $tap = ($this->receive)($url);

    expect($tap->only(['status', 'rejection', 'nfc_tag_id', 'counter', 'stamper_id']))->toBe([
        'status' => TapStatus::Rejected,
        'rejection' => $rejection,
        'nfc_tag_id' => null,
        'counter' => null,
        'stamper_id' => null,
    ])->and(($this->lastCounter)())->toBe(0);
})->with([
    'a tampered MAC' => ['a tampered MAC', TapRejection::BadMac],
    'a malformed e' => ['a malformed e', TapRejection::Malformed],
    'a short c' => ['a short c', TapRejection::Malformed],
]);

it('refuses a tag the platform does not know', function (): void {
    $stranger = (new NfcTag)->forceFill(['uid' => '04FFEEDDCCBBAA', 'key_version' => 1]);

    $tap = ($this->receive)(($this->url)(5, $stranger));

    expect($tap->rejection)->toBe(TapRejection::UnknownTag)
        ->and($tap->nfc_tag_id)->toBeNull();
});

it('refuses a retired tag, a tag no stamper holds and a paused stamper, and still spends the counter', function (string $state, TapRejection $rejection): void {
    $this->context->bypass(fn () => match ($state) {
        'retired' => $this->tag->forceFill(['retired_at' => now()])->save(),
        'unassigned' => $this->stamper->forceFill(['unassigned_at' => now()])->save(),
        'paused' => $this->stamper->forceFill(['status' => StamperStatus::Disabled])->save(),
    });

    $tap = ($this->receive)(($this->url)(5));

    expect($tap->status)->toBe(TapStatus::Rejected)
        ->and($tap->rejection)->toBe($rejection)
        ->and($tap->nfc_tag_id)->toBe($this->tag->id)
        ->and($tap->counter)->toBe(5)
        ->and($tap->business_id)->toBe($state === 'unassigned' ? null : $this->tenants->a1->id)
        ->and(($this->lastCounter)())->toBe(5);
})->with([
    'a retired tag' => ['retired', TapRejection::RetiredTag],
    'a tag no stamper holds' => ['unassigned', TapRejection::UnassignedTag],
    'a paused stamper' => ['paused', TapRejection::StamperDisabled],
]);

it('gives what staff armed the stamper with, once', function (): void {
    $this->context->bypass(fn () => $this->stamper->forceFill(['armed_qty' => 3, 'armed_until' => now()->addSeconds(60)])->save());

    $armed = ($this->receive)(($this->url)(5));
    $next = ($this->receive)(($this->url)(6));

    expect($armed->qty)->toBe(3)
        ->and($next->qty)->toBe(1)
        ->and($this->context->bypass(fn (): ?int => Stamper::query()->whereKey($this->stamper->id)->value('armed_qty')))->toBeNull();
});

it('ignores arming that ran out', function (): void {
    $this->context->bypass(fn () => $this->stamper->forceFill(['armed_qty' => 3, 'armed_until' => now()->subSecond()])->save());

    expect(($this->receive)(($this->url)(5))->qty)->toBe(1);
});

it('decrypts with the global meta key version and the tag\'s own key version', function (): void {
    $this->context->bypass(fn () => $this->tag->forceFill(['key_version' => 2])->save());

    $tap = ($this->receive)(($this->url)(5, keyVersion: 2));

    expect($tap->status)->toBe(TapStatus::Pending);
});

it('keeps the tag\'s counter across a reassignment', function (): void {
    ($this->receive)(($this->url)(5));
    $this->context->bypass(function (): void {
        $this->stamper->forceFill(['unassigned_at' => now()])->save();
        Stamper::factory()->create([
            'business_id' => $this->tenants->a2->id,
            'location_id' => $this->tenants->locationOf($this->tenants->a2)->id,
            'nfc_tag_id' => $this->tag->id,
        ]);
    });

    expect(($this->receive)(($this->url)(5))->rejection)->toBe(TapRejection::Replay)
        ->and(($this->receive)(($this->url)(6))->business_id)->toBe($this->tenants->a2->id);
});

it('never writes the URL or key material to the log', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.json_encode($message->context);
    });
    $url = ($this->url)(5);

    ($this->receive)($url);
    ($this->receive)($url);
    ($this->receive)(['e' => $url['e'], 'c' => strrev($url['c'])]);

    foreach ($logged as $line) {
        expect($line)->not->toContain($url['e'])->not->toContain($url['c'])->not->toContain(SunVectors::AN10922_MASTER_KEY);
    }
});

it('treats a missing key as a server error, never as a tap', function (): void {
    config(['punchcard.nfc.sun_master_key' => null]);
    app()->forgetInstance(KeyDiversifier::class);

    expect(fn () => ($this->receive)(['e' => str_repeat('A', 32), 'c' => str_repeat('B', 16)]))->toThrow(InvalidArgumentException::class)
        ->and($this->context->bypass(fn (): int => Tap::query()->count()))->toBe(0);
});

it('locks the tag without blocking a stamp event\'s foreign key check on it', function (): void {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    ($this->receive)(($this->url)(5));

    expect(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "nfc_tags"') && str_contains($sql, 'for no key update')))->toBeTrue();
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Row locks compile on Postgres only');

it('refuses to run inside a caller\'s transaction, where a rollback would revive the counter', function (): void {
    DB::transaction(fn (): Tap => ($this->receive)(($this->url)(5)));
})->throws(LogicException::class, 'outside any transaction');
