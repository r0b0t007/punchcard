<?php

declare(strict_types=1);

use App\Support\Nfc\AesCmac;
use App\Support\Nfc\SunFailure;
use App\Support\Nfc\SunMessage;
use App\Support\Nfc\SunVerificationFailed;
use App\Support\Nfc\SunVerifier;
use App\Support\Nfc\VerifiedTap;

/*
|--------------------------------------------------------------------------
| SUN tap verification (NXP AN12196)
|--------------------------------------------------------------------------
|
| The AN12196 example uses all-zero keys: the encrypted PICCData decodes to
| UID 04DE5F1EACC040 with read counter 61, and the CMAC verifies.
|
*/

const AN12196_PICC_DATA = 'EF963FF7828658A599F3041510671E88';
const AN12196_CMAC = '94EED9EE65337086';

function zeroKey(): string
{
    return str_repeat("\0", 16);
}

function sunFailure(callable $call): SunVerificationFailed
{
    try {
        $call();
    } catch (SunVerificationFailed $failure) {
        return $failure;
    }

    throw new RuntimeException('Expected SunVerificationFailed');
}

/**
 * Builds the `e` and `c` a tag would emit, the way AN12196 describes it, so
 * tests can use distinct keys and chosen UIDs, counters and tag bytes.
 *
 * @return array{e: string, c: string}
 */
function sunTap(string $metaKey, string $fileKey, string $uid, int $counter, int $tagByte = 0xC7): array
{
    $counterLe = substr(pack('V', $counter), 0, 3);
    $plain = chr($tagByte).hex2bin($uid).$counterLe.str_repeat("\xA5", 5);
    $e = (string) openssl_encrypt($plain, 'aes-128-cbc', $metaKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));

    $sessionKey = AesCmac::compute($fileKey, "\x3C\xC3\x00\x01\x00\x80".hex2bin($uid).$counterLe);
    $full = AesCmac::compute($sessionKey, '');
    $c = '';

    for ($i = 1; $i < 16; $i += 2) {
        $c .= $full[$i];
    }

    return ['e' => strtoupper(bin2hex($e)), 'c' => strtoupper(bin2hex($c))];
}

const META_KEY = 'METAKEY-01234567';
const FILE_KEY = 'FILEKEY-89ABCDEF';

it('decodes the UID and read counter from the AN12196 example', function (): void {
    $message = (new SunVerifier)->decrypt(AN12196_PICC_DATA, zeroKey());

    expect($message->uid)->toBe('04DE5F1EACC040')
        ->and($message->counter)->toBe(61);
});

it('accepts the AN12196 CMAC and returns the verified tap', function (): void {
    $verifier = new SunVerifier;
    $message = $verifier->decrypt(AN12196_PICC_DATA, zeroKey());

    $tap = $verifier->verifyMac($message, AN12196_CMAC, zeroKey());

    expect($tap)->toBeInstanceOf(VerifiedTap::class)
        ->and($tap->uid)->toBe('04DE5F1EACC040')
        ->and($tap->counter)->toBe(61);
});

it('accepts lowercase hex', function (): void {
    $verifier = new SunVerifier;
    $message = $verifier->decrypt(strtolower(AN12196_PICC_DATA), zeroKey());

    $verifier->verifyMac($message, strtolower(AN12196_CMAC), zeroKey());

    expect($message->counter)->toBe(61);
});

it('rejects a tampered CMAC', function (): void {
    $verifier = new SunVerifier;
    $message = $verifier->decrypt(AN12196_PICC_DATA, zeroKey());
    $tampered = substr(AN12196_CMAC, 0, -1).'7';

    expect(sunFailure(fn (): VerifiedTap => $verifier->verifyMac($message, $tampered, zeroKey()))->reason)
        ->toBe(SunFailure::BadMac);
});

it('rejects a CMAC checked with the wrong file read key', function (): void {
    $verifier = new SunVerifier;
    $message = $verifier->decrypt(AN12196_PICC_DATA, zeroKey());

    expect(sunFailure(fn (): VerifiedTap => $verifier->verifyMac($message, AN12196_CMAC, str_repeat("\x01", 16)))->reason)
        ->toBe(SunFailure::BadMac);
});

it('rejects a CMAC for another UID or counter', function (SunMessage $other): void {
    expect(sunFailure(fn (): VerifiedTap => (new SunVerifier)->verifyMac($other, AN12196_CMAC, zeroKey()))->reason)
        ->toBe(SunFailure::BadMac);
})->with([
    'next counter' => [fn (): SunMessage => new SunMessage('04DE5F1EACC040', 62)],
    'other UID' => [fn (): SunMessage => new SunMessage('04DE5F1EACC041', 61)],
]);

it('rejects PICCData decrypted with the wrong meta read key', function (): void {
    expect(sunFailure(fn (): SunMessage => (new SunVerifier)->decrypt(AN12196_PICC_DATA, str_repeat("\x01", 16)))->reason)
        ->toBe(SunFailure::Malformed);
});

it('rejects malformed PICCData', function (string $piccData): void {
    expect(sunFailure(fn (): SunMessage => (new SunVerifier)->decrypt($piccData, zeroKey()))->reason)
        ->toBe(SunFailure::Malformed);
})->with([
    'empty' => [''],
    'too short' => [substr(AN12196_PICC_DATA, 0, 30)],
    'too long' => [AN12196_PICC_DATA.'00'],
    'not hex' => ['ZZ963FF7828658A599F3041510671E88'],
    'odd length' => [AN12196_PICC_DATA.'0'],
]);

it('rejects a malformed CMAC', function (string $cmac): void {
    $verifier = new SunVerifier;
    $message = $verifier->decrypt(AN12196_PICC_DATA, zeroKey());

    expect(sunFailure(fn (): VerifiedTap => $verifier->verifyMac($message, $cmac, zeroKey()))->reason)
        ->toBe(SunFailure::Malformed);
})->with([
    'empty' => [''],
    'too short' => ['94EED9EE653370'],
    'too long' => [AN12196_CMAC.'00'],
    'not hex' => ['94EED9EE6533708G'],
]);

it('rejects keys that are not 16 bytes', function (): void {
    $verifier = new SunVerifier;

    expect(sunFailure(fn (): SunMessage => $verifier->decrypt(AN12196_PICC_DATA, str_repeat("\0", 32)))->reason)
        ->toBe(SunFailure::Malformed);

    $message = $verifier->decrypt(AN12196_PICC_DATA, zeroKey());

    expect(sunFailure(fn (): VerifiedTap => $verifier->verifyMac($message, AN12196_CMAC, ''))->reason)
        ->toBe(SunFailure::Malformed);
});

it('keeps tap data out of failure messages', function (): void {
    $verifier = new SunVerifier;
    $message = $verifier->decrypt(AN12196_PICC_DATA, zeroKey());
    $tampered = substr(AN12196_CMAC, 0, -1).'7';

    $failures = [
        sunFailure(fn (): SunMessage => $verifier->decrypt('ZZ'.substr(AN12196_PICC_DATA, 2), zeroKey())),
        sunFailure(fn (): SunMessage => $verifier->decrypt(AN12196_PICC_DATA, str_repeat("\x01", 16))),
        sunFailure(fn (): VerifiedTap => $verifier->verifyMac($message, $tampered, zeroKey())),
    ];

    foreach ($failures as $failure) {
        expect(strtoupper($failure->getMessage()))
            ->not->toContain(substr(AN12196_PICC_DATA, 2))
            ->not->toContain(substr($tampered, 0, 8))
            ->not->toContain('04DE5F1EACC040')
            ->not->toContain('61');
    }
});

it('rejects a message with an invalid UID or counter', function (SunMessage $message): void {
    expect(sunFailure(fn (): VerifiedTap => (new SunVerifier)->verifyMac($message, AN12196_CMAC, zeroKey()))->reason)
        ->toBe(SunFailure::Malformed);
})->with([
    'UID not hex' => [fn (): SunMessage => new SunMessage('04DE5F1EACC04Z', 61)],
    'UID too short' => [fn (): SunMessage => new SunMessage('04DE5F1EACC0', 61)],
    'negative counter' => [fn (): SunMessage => new SunMessage('04DE5F1EACC040', -1)],
    'counter above 24 bits' => [fn (): SunMessage => new SunMessage('04DE5F1EACC040', 0x1000000)],
]);

it('uses the meta read key to decrypt and the file read key to verify', function (): void {
    $verifier = new SunVerifier;
    $tap = sunTap(META_KEY, FILE_KEY, '04A1B2C3D4E5F6', 1234);

    $message = $verifier->decrypt($tap['e'], META_KEY);

    expect($verifier->verifyMac($message, $tap['c'], FILE_KEY))
        ->toEqual(new VerifiedTap('04A1B2C3D4E5F6', 1234))
        ->and(sunFailure(fn (): VerifiedTap => $verifier->verifyMac($message, $tap['c'], META_KEY))->reason)
        ->toBe(SunFailure::BadMac)
        ->and(sunFailure(fn (): SunMessage => $verifier->decrypt($tap['e'], FILE_KEY))->reason)
        ->toBe(SunFailure::Malformed);
});

it('accepts the lowest and highest read counter', function (int $counter): void {
    $verifier = new SunVerifier;
    $tap = sunTap(META_KEY, FILE_KEY, '04A1B2C3D4E5F6', $counter);

    expect($verifier->verifyMac($verifier->decrypt($tap['e'], META_KEY), $tap['c'], FILE_KEY)->counter)
        ->toBe($counter);
})->with([0, 0xFFFFFF]);

it('rejects PICCData that does not mirror a 7-byte UID and the counter', function (int $tagByte): void {
    $tap = sunTap(META_KEY, FILE_KEY, '04A1B2C3D4E5F6', 5, $tagByte);

    expect(sunFailure(fn (): SunMessage => (new SunVerifier)->decrypt($tap['e'], META_KEY))->reason)
        ->toBe(SunFailure::Malformed);
})->with([
    'counter not mirrored' => [0x87],
    'UID not mirrored' => [0x47],
    '4-byte UID' => [0xC4],
]);

it('rejects PICCData with a flipped byte', function (): void {
    $tap = sunTap(META_KEY, FILE_KEY, '04A1B2C3D4E5F6', 5);
    $flipped = sprintf('%02X', hexdec(substr($tap['e'], 0, 2)) ^ 0x01).substr($tap['e'], 2);

    expect(sunFailure(fn (): SunMessage => (new SunVerifier)->decrypt($flipped, META_KEY))->reason)
        ->toBe(SunFailure::Malformed);
});

it('keeps keys and tap data out of stack traces', function (): void {
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        $verifier = new SunVerifier;
        $tap = sunTap(META_KEY, FILE_KEY, '04A1B2C3D4E5F6', 5);
        $message = $verifier->decrypt($tap['e'], META_KEY);

        $traces = [
            sunFailure(fn (): SunMessage => $verifier->decrypt($tap['e'], FILE_KEY))->getTraceAsString(),
            sunFailure(fn (): VerifiedTap => $verifier->verifyMac($message, '0000000000000000', FILE_KEY))->getTraceAsString(),
            sunFailure(fn (): SunMessage => $verifier->decrypt('ZZ', META_KEY))->getTraceAsString(),
        ];
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    foreach ($traces as $trace) {
        expect($trace)
            ->not->toContain('METAKEY')
            ->not->toContain('FILEKEY')
            ->not->toContain(substr($tap['e'], 0, 12))
            ->not->toContain('000000000000');
    }
});
