<?php

declare(strict_types=1);

use App\Enums\TapRejection;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Nfc\SunMessage;
use App\Support\Nfc\SunVerifier;
use App\Support\Nfc\VerifiedTap;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Tests\Support\SunVectors as V;

/*
|--------------------------------------------------------------------------
| Tag key diversification (NXP AN10922, AES-128)
|--------------------------------------------------------------------------
|
| Every tag key comes from one master key, so no per-tag secret is stored.
| The golden keys pin our input layout: changing it would break every tag
| already provisioned.
|
*/

it('matches the NXP AN10922 AES-128 example', function (): void {
    $key = KeyDiversifier::diversify((string) hex2bin(V::AN10922_MASTER_KEY), (string) hex2bin(V::AN10922_INPUT));

    expect(strtoupper(bin2hex($key)))->toBe(V::AN10922_KEY);
});

it('pads every input to 32 bytes as AN10922 specifies', function (string $input, string $expected): void {
    $key = KeyDiversifier::diversify((string) hex2bin(V::AN10922_MASTER_KEY), (string) hex2bin($input));

    expect(strtoupper(bin2hex($key)))->toBe($expected);
})->with(V::AN10922_LENGTHS);

it('derives the documented keys', function (): void {
    $keys = V::diversifier();

    expect(strtoupper(bin2hex($keys->metaReadKey(1))))->toBe(V::DERIVED_KEYS['meta v1'])
        ->and(strtoupper(bin2hex($keys->metaReadKey(2))))->toBe(V::DERIVED_KEYS['meta v2'])
        ->and(strtoupper(bin2hex($keys->fileReadKey(V::UID, 1))))->toBe(V::DERIVED_KEYS['file v1'])
        ->and(strtoupper(bin2hex($keys->fileReadKey(V::UID, 2))))->toBe(V::DERIVED_KEYS['file v2'])
        ->and(strtoupper(bin2hex($keys->tagKey(KeyDiversifier::APP_MASTER_KEY, V::UID, 1))))
        ->toBe(V::DERIVED_KEYS['app master v1']);
});

it('gives every tag its own file read key', function (): void {
    $keys = V::diversifier();

    expect($keys->fileReadKey(V::UID, 1))->not->toBe($keys->fileReadKey('04A1B2C3D4E5F7', 1))
        ->and(strlen($keys->fileReadKey(V::UID, 1)))->toBe(16);
});

it('accepts the UID in either case', function (): void {
    $keys = V::diversifier();

    expect($keys->fileReadKey(strtolower(V::UID), 1))->toBe($keys->fileReadKey(V::UID, 1));
});

it('never derives the same key for two key numbers', function (): void {
    $keys = V::diversifier();
    $derived = [
        $keys->metaReadKey(1),
        $keys->fileReadKey(V::UID, 1),
        ...array_map(fn (int $number): string => $keys->tagKey($number, V::UID, 1), [0, 3, 4]),
    ];

    expect(array_unique($derived))->toHaveCount(5);
});

it('only derives the meta read key system-wide', function (): void {
    V::diversifier()->tagKey(KeyDiversifier::META_READ_KEY, V::UID, 1);
})->throws(InvalidArgumentException::class);

it('rejects invalid derivation inputs', function (string $method, array $arguments): void {
    V::diversifier()->{$method}(...$arguments);
})->throws(InvalidArgumentException::class)->with([
    'UID too short' => ['fileReadKey', ['04A1B2C3D4E5', 1]],
    'UID not hex' => ['fileReadKey', ['04A1B2C3D4E5FZ', 1]],
    'version zero' => ['metaReadKey', [0]],
    'version above 16 bits' => ['fileReadKey', [V::UID, 0x10000]],
    'key number 5' => ['tagKey', [5, V::UID, 1]],
    'key number -1' => ['tagKey', [-1, V::UID, 1]],
]);

it('needs an AN10922 input of 1 to 31 bytes', function (int $length): void {
    KeyDiversifier::diversify(V::zeroKey(), str_repeat("\0", $length));
})->throws(InvalidArgumentException::class)->with([0, 32]);

it('rejects a missing or malformed master key', function (?string $hex): void {
    expect(fn (): KeyDiversifier => KeyDiversifier::fromHex($hex))->toThrow(InvalidArgumentException::class);
})->with([
    'missing' => [null],
    'empty' => [''],
    'too short' => [substr(V::AN10922_MASTER_KEY, 0, 30)],
    'not hex' => ['ZZ'.substr(V::AN10922_MASTER_KEY, 2)],
    'trailing carriage return' => [V::AN10922_MASTER_KEY."\r"],
    'trailing space' => [V::AN10922_MASTER_KEY.' '],
]);

it('resolves from NFC_SUN_MASTER_KEY in config', function (): void {
    config(['punchcard.nfc.sun_master_key' => V::AN10922_MASTER_KEY]);

    expect(app(KeyDiversifier::class)->metaReadKey(1))->toBe(V::diversifier()->metaReadKey(1));
});

it('fails clearly when NFC_SUN_MASTER_KEY is not set', function (): void {
    config(['punchcard.nfc.sun_master_key' => null]);

    app(KeyDiversifier::class);
})->throws(InvalidArgumentException::class, 'NFC_SUN_MASTER_KEY');

it('keeps the master key and derived keys out of stack traces', function (): void {
    $previous = ini_set('zend.exception_ignore_args', '0');

    expect($previous)->not->toBeFalse();

    try {
        $master = (string) hex2bin(V::AN10922_MASTER_KEY);
        $traces = [];

        foreach ([
            fn (): string => KeyDiversifier::diversify($master, ''),
            fn (): KeyDiversifier => KeyDiversifier::fromHex(substr(V::AN10922_MASTER_KEY, 0, 30)),
            fn (): string => V::diversifier()->tagKey(9, V::UID, 1),
        ] as $call) {
            try {
                $call();
            } catch (InvalidArgumentException $exception) {
                $traces[] = $exception->getTraceAsString();
            }
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    expect($traces)->toHaveCount(3);

    foreach ($traces as $trace) {
        expect($trace)
            ->not->toContain(substr($master, 0, 6))
            ->not->toContain(substr(V::AN10922_MASTER_KEY, 0, 12))
            ->not->toContain(strtolower(substr(V::AN10922_MASTER_KEY, 0, 12)));
    }
});

it('never exposes the master key through dumps or serialization', function (): void {
    $keys = V::diversifier();
    $master = (string) hex2bin(V::AN10922_MASTER_KEY);

    $dumper = new CliDumper;
    $dumper->setColors(false);
    $dumps = [
        (string) $dumper->dump((new VarCloner)->cloneVar($keys), true),
        print_r($keys, true),
        var_export($keys, true),
        print_r((array) $keys, true),
    ];

    foreach ($dumps as $dump) {
        expect($dump)
            ->not->toContain($master)
            ->not->toContain(substr($master, 2, 6))
            ->not->toContain(substr(V::AN10922_MASTER_KEY, 0, 12));
    }

    expect(fn (): string => serialize($keys))->toThrow(Exception::class);
});

it('verifies a tap from a tag provisioned with the derived keys', function (): void {
    $keys = V::diversifier();
    $tap = V::PROVISIONED_TAP;
    $verifier = new SunVerifier;

    $verified = $verifier->verifyMac(
        $verifier->decrypt($tap['e'], $keys->metaReadKey(1)),
        $tap['c'],
        $keys->fileReadKey($tap['uid'], 1),
    );

    expect($verified->uid)->toBe($tap['uid'])
        ->and($verified->counter)->toBe($tap['counter']);
});

it('rejects that tap under another stamper key version or meta key version', function (): void {
    $keys = V::diversifier();
    $tap = V::PROVISIONED_TAP;
    $verifier = new SunVerifier;
    $message = $verifier->decrypt($tap['e'], $keys->metaReadKey(1));

    expect(V::failure(fn (): VerifiedTap => $verifier->verifyMac($message, $tap['c'], $keys->fileReadKey($tap['uid'], 2)))->reason)
        ->toBe(TapRejection::BadMac)
        ->and(V::failure(fn (): SunMessage => $verifier->decrypt($tap['e'], $keys->metaReadKey(2)))->reason)
        ->toBe(TapRejection::Malformed);
});
