<?php

declare(strict_types=1);

use App\Actions\Taps\ReceiveTap;
use App\Enums\StamperStatus;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\Business;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Models\Tap;
use App\Models\User;
use App\Support\Nfc\FakeTap;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
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

it('links a pending tap to the session that tapped, with the tap itself, and only a pending one', function (): void {
    $url = ($this->url)(5);
    $pending = app(ReceiveTap::class)->handle($url['e'], $url['c'], null, null, null, 'a-hash');
    $replay = app(ReceiveTap::class)->handle($url['e'], $url['c'], null, null, null, 'a-hash');

    expect($pending->claim_token_hash)->toBe('a-hash')
        ->and($replay->rejection)->toBe(TapRejection::Replay)
        ->and($replay->claim_token_hash)->toBeNull();
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

    $replay = ($this->receive)(($this->url)(5));

    expect($replay->rejection)->toBe(TapRejection::Replay)
        ->and($replay->only(['stamper_id', 'business_id']))->toBe(['stamper_id' => $this->stamper->id, 'business_id' => $this->tenants->a1->id])
        ->and(($this->receive)(($this->url)(6))->business_id)->toBe($this->tenants->a2->id);
});

it('never writes the URL or key material to the log, a failed tap\'s report included', function (string $failure): void {
    // Each line as a log handler writes it: the message, the context, and a reported exception with its stack trace,
    // where a function's arguments show (the first 15 characters of each) unless marked #[\SensitiveParameter]. Pinned
    // here: a php.ini that drops arguments from traces (production's) would let a leak through unseen.
    $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    $argLength = ini_set('zend.exception_string_param_max_len', '15');
    try {
        $logged = new ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $message) use ($logged): void {
            $exception = $message->context['exception'] ?? null;
            $logged[] = $message->message.json_encode(Arr::except($message->context, 'exception')).($exception instanceof Throwable ? (string) $exception : '');
        });
        $url = ($this->url)(5);

        ($this->receive)($url);
        ($this->receive)($url);
        ($this->receive)(['e' => $url['e'], 'c' => strrev($url['c'])]);
        // A tap that fails on the server (CHW-149): the handler reports it, with its trace, through /t itself. A missing
        // key fails as the Action is built; a bad key version once the URL is in hand; a failed write once the tap is
        // verified, in spend().
        $failing = ($this->url)(6);
        match ($failure) {
            'a missing key' => config(['punchcard.nfc.sun_master_key' => null]),
            'a bad key version' => config(['punchcard.nfc.key_version' => 'two']),
            'a failed write' => Tap::creating(fn () => throw new RuntimeException('The tap could not be written.')),
        };
        app()->forgetInstance(KeyDiversifier::class);
        $this->get(route('taps.receive', $failing))->assertServerError();

        $lines = collect($logged->getArrayCopy());
        $reported = ['a missing key' => 'NFC_SUN_MASTER_KEY', 'a bad key version' => 'NFC_SUN_KEY_VERSION', 'a failed write' => 'could not be written'][$failure];
        expect($lines->filter(fn (string $line): bool => str_contains($line, $reported)))->not->toBeEmpty();
        if ($failure !== 'a missing key') {
            // The trace rendered the tap's arguments, hidden: the check below reads a real trace.
            expect($lines->filter(fn (string $line): bool => str_contains($line, 'ReceiveTap->handle(Object(SensitiveParameterValue)')))->not->toBeEmpty();
        }
        $secrets = [$url['e'], $url['c'], $failing['e'], $failing['c'], SunVectors::AN10922_MASTER_KEY];
        foreach ($secrets as $secret) {
            // Whole, or as much as a trace keeps of an argument.
            expect($lines->filter(fn (string $line): bool => str_contains($line, substr($secret, 0, 15))))->toBeEmpty();
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        ini_set('zend.exception_string_param_max_len', (string) $argLength);
    }
})->with(['a missing key', 'a bad key version', 'a failed write']);

it('never keeps the tap URL in the session as the page to go back to', function (string $outcome): void {
    // The session keeps each page's full URL; this one carries e and c (CHW-149, sun-nfc-verification).
    $url = ($this->url)(5);
    if ($outcome === 'a server error') {
        config(['punchcard.nfc.key_version' => 'two']);
    }

    $response = $this->get(route('taps.receive', $url));

    $outcome === 'a server error' ? $response->assertServerError() : $response->assertRedirect();
    expect((string) session()->previousUrl())->not->toContain($url['e'])->not->toContain($url['c'])
        ->and(session()->all())->not->toContain($url['e']);
})->with(['a tap', 'a server error']);

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

it('puts a replay where its URL was read, the next counter we spent, even one we never saw', function (): void {
    // Counter 4 was read at A1 (a reader app, say) but never sent; 5 was.
    ($this->receive)(($this->url)(5));
    $this->context->bypass(function (): void {
        $this->stamper->forceFill(['unassigned_at' => now()])->save();
        Stamper::factory()->create([
            'business_id' => $this->tenants->a2->id,
            'location_id' => $this->tenants->locationOf($this->tenants->a2)->id,
            'nfc_tag_id' => $this->tag->id,
        ]);
    });
    ($this->receive)(($this->url)(6));

    $replay = ($this->receive)(($this->url)(4));

    expect($replay->rejection)->toBe(TapRejection::Replay)
        ->and($replay->only(['stamper_id', 'business_id']))->toBe(['stamper_id' => $this->stamper->id, 'business_id' => $this->tenants->a1->id]);
});

it('puts a replay of a URL read while nobody held the tag at no business', function (): void {
    $this->context->bypass(fn () => $this->stamper->forceFill(['unassigned_at' => now()])->save());
    expect(($this->receive)(($this->url)(5))->rejection)->toBe(TapRejection::UnassignedTag);
    $this->context->bypass(fn () => Stamper::factory()->create([
        'business_id' => $this->tenants->a2->id,
        'location_id' => $this->tenants->locationOf($this->tenants->a2)->id,
        'nfc_tag_id' => $this->tag->id,
    ]));

    $replay = ($this->receive)(($this->url)(5));

    expect($replay->rejection)->toBe(TapRejection::Replay)
        ->and($replay->only(['stamper_id', 'business_id']))->toBe(['stamper_id' => null, 'business_id' => null]);
});

it('names no business for a replay it can\'t place: the tag moved between counters, or the old taps were pruned', function (string $case): void {
    ($this->receive)(($this->url)(5));
    $this->context->bypass(function (): void {
        $this->stamper->forceFill(['unassigned_at' => now()])->save();
        Stamper::factory()->create([
            'business_id' => $this->tenants->a2->id,
            'location_id' => $this->tenants->locationOf($this->tenants->a2)->id,
            'nfc_tag_id' => $this->tag->id,
        ]);
    });
    ($this->receive)(($this->url)(7));

    if ($case === 'pruned') {
        // The log keeps taps for a while only (taps.retention_days): A1's are gone.
        DB::table('taps')->where('counter', 5)->delete();
    }

    // Counter 6 was read between the move and counter 7; counter 3 before A1's pruned taps.
    $replay = ($this->receive)(($this->url)($case === 'pruned' ? 3 : 6));

    expect($replay->rejection)->toBe(TapRejection::Replay)
        ->and($replay->only(['stamper_id', 'business_id', 'location_id']))->toBe(['stamper_id' => null, 'business_id' => null, 'location_id' => null]);
})->with(['moved between', 'pruned']);

it('places a replay of a counter never sent between two spent at the same stamper', function (): void {
    ($this->receive)(($this->url)(5));
    $atA2 = $this->context->bypass(function (): Stamper {
        $this->stamper->forceFill(['unassigned_at' => now()])->save();

        return Stamper::factory()->create([
            'business_id' => $this->tenants->a2->id,
            'location_id' => $this->tenants->locationOf($this->tenants->a2)->id,
            'nfc_tag_id' => $this->tag->id,
        ]);
    });
    ($this->receive)(($this->url)(7));
    ($this->receive)(($this->url)(9));

    // Counter 8 was read at A2: between 7 and 9, both spent there.
    $replay = ($this->receive)(($this->url)(8));

    expect($replay->only(['stamper_id', 'business_id']))->toBe(['stamper_id' => $atA2->id, 'business_id' => $this->tenants->a2->id]);
});

it('names no business for a URL read while the tag was away, once it came back', function (): void {
    // A1, then A2, then A1 again: each stay is its own stamper.
    $assign = fn (Business $business): Stamper => $this->context->bypass(function () use ($business): Stamper {
        Stamper::query()->current()->where('nfc_tag_id', $this->tag->id)->firstOrFail()->forceFill(['unassigned_at' => now()])->save();

        return Stamper::factory()->create([
            'business_id' => $business->id,
            'location_id' => $this->tenants->locationOf($business)->id,
            'nfc_tag_id' => $this->tag->id,
        ]);
    });
    ($this->receive)(($this->url)(5));
    $assign($this->tenants->a2);
    ($this->receive)(($this->url)(7));
    $assign($this->tenants->a1);
    ($this->receive)(($this->url)(9));

    // Counter 8 was read at A2 or back at A1: no telling.
    expect(($this->receive)(($this->url)(8))->only(['stamper_id', 'business_id']))->toBe(['stamper_id' => null, 'business_id' => null]);
});
