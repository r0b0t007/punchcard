<?php

declare(strict_types=1);

use App\Enums\TapRejection;
use App\Support\Nfc\SunMessage;
use App\Support\Nfc\SunVerifier;
use App\Support\Nfc\VerifiedTap;
use Tests\Support\SunVectors as V;

/*
|--------------------------------------------------------------------------
| SUN tap verification (NXP AN12196)
|--------------------------------------------------------------------------
|
| The AN12196 example uses all-zero keys. The other taps in SunVectors were
| generated with the skill's independent reference implementation, with a
| distinct meta read key and file read key.
|
*/

it('decodes the UID and read counter from the AN12196 example', function (): void {
    $message = (new SunVerifier)->decrypt(V::AN12196_PICC_DATA, V::zeroKey());

    expect($message->uid)->toBe(V::AN12196_UID)
        ->and($message->counter)->toBe(V::AN12196_COUNTER);
});

it('accepts the AN12196 CMAC and returns the verified tap', function (): void {
    $verifier = new SunVerifier;

    $tap = $verifier->verifyMac($verifier->decrypt(V::AN12196_PICC_DATA, V::zeroKey()), V::AN12196_CMAC, V::zeroKey());

    expect($tap)->toBeInstanceOf(VerifiedTap::class)
        ->and($tap->uid)->toBe(V::AN12196_UID)
        ->and($tap->counter)->toBe(V::AN12196_COUNTER);
});

it('accepts lowercase hex and returns an uppercase UID', function (): void {
    $verifier = new SunVerifier;
    $message = $verifier->decrypt(strtolower(V::AN12196_PICC_DATA), V::zeroKey());

    expect($verifier->verifyMac($message, strtolower(V::AN12196_CMAC), V::zeroKey())->uid)
        ->toBe(V::AN12196_UID);
});

it('verifies reference taps with distinct meta and file read keys', function (string $name): void {
    $verifier = new SunVerifier;
    $tap = V::tap($name);

    $verified = $verifier->verifyMac($verifier->decrypt($tap['e'], V::metaKey()), $tap['c'], V::fileKey());

    expect($verified->uid)->toBe($tap['uid'])
        ->and($verified->counter)->toBe($tap['counter']);
})->with(array_keys(V::TAPS));

it('uses each key only for its own step', function (): void {
    $verifier = new SunVerifier;
    $tap = V::tap('round trip');
    $message = $verifier->decrypt($tap['e'], V::metaKey());

    expect(V::failure(fn (): VerifiedTap => $verifier->verifyMac($message, $tap['c'], V::metaKey()))->reason)
        ->toBe(TapRejection::BadMac)
        ->and(V::failure(fn (): SunMessage => $verifier->decrypt($tap['e'], V::fileKey()))->reason)
        ->toBe(TapRejection::Malformed);
});

it('only lets the verifier create messages and verified taps', function (string $class): void {
    expect((new ReflectionClass($class))->getConstructor()?->isPrivate())->toBeTrue();
})->with([SunMessage::class, VerifiedTap::class]);

it('refuses to serialize a verified tap or a message', function (): void {
    $verifier = new SunVerifier;
    $message = $verifier->decrypt(V::AN12196_PICC_DATA, V::zeroKey());
    $tap = $verifier->verifyMac($message, V::AN12196_CMAC, V::zeroKey());

    expect(fn (): string => serialize($tap))->toThrow(LogicException::class)
        ->and(fn (): string => serialize($message))->toThrow(LogicException::class)
        ->and(fn (): mixed => unserialize('O:'.strlen(VerifiedTap::class).':"'.VerifiedTap::class.'":2:{s:3:"uid";s:14:"04A1B2C3D4E5F6";s:7:"counter";i:9;}'))
        ->toThrow(LogicException::class);
});

it('rejects a tampered CMAC', function (): void {
    $verifier = new SunVerifier;
    $message = $verifier->decrypt(V::AN12196_PICC_DATA, V::zeroKey());

    expect(V::failure(fn (): VerifiedTap => $verifier->verifyMac($message, substr(V::AN12196_CMAC, 0, -1).'7', V::zeroKey()))->reason)
        ->toBe(TapRejection::BadMac);
});

it('rejects a CMAC checked with the wrong file read key', function (): void {
    $verifier = new SunVerifier;
    $message = $verifier->decrypt(V::AN12196_PICC_DATA, V::zeroKey());

    expect(V::failure(fn (): VerifiedTap => $verifier->verifyMac($message, V::AN12196_CMAC, str_repeat("\x01", 16)))->reason)
        ->toBe(TapRejection::BadMac);
});

it('rejects a CMAC taken from another tap', function (string $other): void {
    $verifier = new SunVerifier;
    $message = $verifier->decrypt(V::tap($other)['e'], V::metaKey());

    expect(V::failure(fn (): VerifiedTap => $verifier->verifyMac($message, V::tap('counter 61')['c'], V::fileKey()))->reason)
        ->toBe(TapRejection::BadMac);
})->with(['counter 62', 'other UID']);

it('rejects PICCData decrypted with the wrong meta read key', function (): void {
    expect(V::failure(fn (): SunMessage => (new SunVerifier)->decrypt(V::AN12196_PICC_DATA, str_repeat("\x01", 16)))->reason)
        ->toBe(TapRejection::Malformed);
});

it('rejects PICCData that does not mirror a 7-byte UID and the counter', function (string $piccData): void {
    expect(V::failure(fn (): SunMessage => (new SunVerifier)->decrypt($piccData, V::metaKey()))->reason)
        ->toBe(TapRejection::Malformed);
})->with(V::WRONG_TAG_BYTE);

it('rejects PICCData with a flipped byte', function (): void {
    $e = V::tap('round trip')['e'];
    $flipped = sprintf('%02X', hexdec(substr($e, 0, 2)) ^ 0x01).substr($e, 2);

    expect(V::failure(fn (): SunMessage => (new SunVerifier)->decrypt($flipped, V::metaKey()))->reason)
        ->toBe(TapRejection::Malformed);
});

it('rejects malformed PICCData', function (string $piccData): void {
    expect(V::failure(fn (): SunMessage => (new SunVerifier)->decrypt($piccData, V::zeroKey()))->reason)
        ->toBe(TapRejection::Malformed);
})->with([
    'empty' => [''],
    'too short' => [substr(V::AN12196_PICC_DATA, 0, 30)],
    'too long' => [V::AN12196_PICC_DATA.'00'],
    'not hex' => ['ZZ'.substr(V::AN12196_PICC_DATA, 2)],
    'odd length' => [V::AN12196_PICC_DATA.'0'],
]);

it('rejects a malformed CMAC', function (string $cmac): void {
    $verifier = new SunVerifier;
    $message = $verifier->decrypt(V::AN12196_PICC_DATA, V::zeroKey());

    expect(V::failure(fn (): VerifiedTap => $verifier->verifyMac($message, $cmac, V::zeroKey()))->reason)
        ->toBe(TapRejection::Malformed);
})->with([
    'empty' => [''],
    'too short' => [substr(V::AN12196_CMAC, 0, 14)],
    'too long' => [V::AN12196_CMAC.'00'],
    'not hex' => [substr(V::AN12196_CMAC, 0, 15).'G'],
]);

it('treats a meta read key that is not 16 bytes as a server error, not a bad tap', function (): void {
    (new SunVerifier)->decrypt(V::AN12196_PICC_DATA, str_repeat("\0", 32));
})->throws(InvalidArgumentException::class);

it('treats a file read key that is not 16 bytes as a server error, not a bad tap', function (): void {
    $verifier = new SunVerifier;

    $verifier->verifyMac($verifier->decrypt(V::AN12196_PICC_DATA, V::zeroKey()), V::AN12196_CMAC, '');
})->throws(InvalidArgumentException::class);

it('keeps tap data out of failure messages', function (): void {
    $verifier = new SunVerifier;
    $message = $verifier->decrypt(V::AN12196_PICC_DATA, V::zeroKey());
    $tampered = substr(V::AN12196_CMAC, 0, -1).'7';

    $failures = [
        V::failure(fn (): SunMessage => $verifier->decrypt('ZZ'.substr(V::AN12196_PICC_DATA, 2), V::zeroKey())),
        V::failure(fn (): SunMessage => $verifier->decrypt(V::AN12196_PICC_DATA, str_repeat("\x01", 16))),
        V::failure(fn (): VerifiedTap => $verifier->verifyMac($message, $tampered, V::zeroKey())),
    ];

    foreach ($failures as $failure) {
        expect(strtoupper($failure->getMessage()))
            ->not->toContain(substr(V::AN12196_PICC_DATA, 2))
            ->not->toContain(substr($tampered, 0, 8))
            ->not->toContain(V::AN12196_UID)
            ->not->toContain('61');
    }
});

it('keeps keys and tap data out of stack traces', function (): void {
    $previous = ini_set('zend.exception_ignore_args', '0');

    expect($previous)->not->toBeFalse();

    try {
        $verifier = new SunVerifier;
        $tap = V::tap('round trip');
        $message = $verifier->decrypt($tap['e'], V::metaKey());

        $traces = [
            V::failure(fn (): SunMessage => $verifier->decrypt($tap['e'], V::fileKey()))->getTraceAsString(),
            V::failure(fn (): VerifiedTap => $verifier->verifyMac($message, '0000000000000000', V::fileKey()))->getTraceAsString(),
            V::failure(fn (): SunMessage => $verifier->decrypt('ZZ', V::metaKey()))->getTraceAsString(),
        ];
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    foreach ($traces as $trace) {
        expect($trace)->toContain('SensitiveParameterValue');

        foreach ([V::metaKey(), V::fileKey()] as $key) {
            expect($trace)
                ->not->toContain(substr($key, 0, 6))
                ->not->toContain(substr(bin2hex($key), 0, 12))
                ->not->toContain(substr(strtoupper(bin2hex($key)), 0, 12));
        }

        expect($trace)
            ->not->toContain(substr($tap['e'], 0, 12))
            ->not->toContain('000000000000');
    }
});
