<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Regenerate and check the stored NFC test vectors
|--------------------------------------------------------------------------
|
| php tests/Support/generate-sun-vectors.php
|
| Rebuilds every generated value in Tests\Support\SunVectors with the skill's
| framework-free reference (SunReference: CMAC, SUN verify, AN10922), never
| with app code, prints them and exits 1 if any stored value differs. Run it
| after changing a vector or the reference.
|
*/

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../../.claude/skills/sun-nfc-verification/reference.php';

use Tests\Support\SunVectors as V;

/** Builds the `e` and `c` a tag emits (AN12196), checked by the reference verifier. */
function referenceTap(string $metaKey, string $fileKey, string $uid, int $counter, int $tagByte = 0xC7): array
{
    $counterLe = substr(pack('V', $counter), 0, 3);
    $plain = chr($tagByte).hex2bin($uid).$counterLe.str_repeat("\xA5", 5);
    $e = strtoupper(bin2hex((string) openssl_encrypt($plain, 'aes-128-cbc', $metaKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16))));

    $full = SunReference::cmac(SunReference::cmac($fileKey, "\x3C\xC3\x00\x01\x00\x80".hex2bin($uid).$counterLe), '');
    $c = '';

    for ($i = 1; $i < 16; $i += 2) {
        $c .= $full[$i];
    }

    $c = strtoupper(bin2hex($c));

    if ($tagByte === 0xC7 && SunReference::verify($e, $c, $metaKey, $fileKey) !== ['uid' => $uid, 'counter' => $counter]) {
        throw new RuntimeException("Reference rejected the tap for {$uid}/{$counter}.");
    }

    return ['e' => $e, 'c' => $c];
}

$hex = static fn (string $binary): string => strtoupper(bin2hex($binary));
$master = (string) hex2bin(V::AN10922_MASTER_KEY);
$uid = (string) hex2bin(V::UID);
$generated = [];

foreach (V::TAPS as $name => [$tapUid, $counter]) {
    $tap = referenceTap(V::metaKey(), V::fileKey(), $tapUid, $counter);
    $generated["TAPS[$name]"] = [$tapUid, $counter, $tap['e'], $tap['c']];
}

foreach (['counter not mirrored (0x87)' => 0x87, 'UID not mirrored (0x47)' => 0x47, '4-byte UID (0xC4)' => 0xC4] as $name => $tagByte) {
    $generated["WRONG_TAG_BYTE[$name]"] = referenceTap(V::metaKey(), V::fileKey(), V::UID, 5, $tagByte)['e'];
}

$generated['AN10922_KEY'] = $hex(SunReference::an10922($master, (string) hex2bin(V::AN10922_INPUT)));

foreach (V::AN10922_LENGTHS as $name => [$input]) {
    $generated["AN10922_LENGTHS[$name]"] = [$input, $hex(SunReference::an10922($master, (string) hex2bin($input)))];
}

$derive = static fn (int $keyNumber, string $tagUid, int $version): string => $hex(SunReference::an10922(
    $master,
    chr($keyNumber).$tagUid.'punchcard'.pack('n', $version),
));

$generated['DERIVED_KEYS'] = [
    'meta v1' => $derive(1, '', 1),
    'meta v2' => $derive(1, '', 2),
    'file v1' => $derive(2, $uid, 1),
    'file v2' => $derive(2, $uid, 2),
    'app master v1' => $derive(0, $uid, 1),
];

$provisioned = referenceTap(
    (string) hex2bin($generated['DERIVED_KEYS']['meta v1']),
    (string) hex2bin($generated['DERIVED_KEYS']['file v1']),
    V::UID,
    V::PROVISIONED_TAP['counter'],
);
$generated['PROVISIONED_TAP'] = ['uid' => V::UID, 'counter' => V::PROVISIONED_TAP['counter'], 'e' => $provisioned['e'], 'c' => $provisioned['c']];

$stored = [];

foreach (V::TAPS as $name => $tap) {
    $stored["TAPS[$name]"] = $tap;
}

foreach (V::WRONG_TAG_BYTE as $name => $e) {
    $stored["WRONG_TAG_BYTE[$name]"] = $e;
}

$stored['AN10922_KEY'] = V::AN10922_KEY;

foreach (V::AN10922_LENGTHS as $name => $pair) {
    $stored["AN10922_LENGTHS[$name]"] = $pair;
}

$stored['DERIVED_KEYS'] = V::DERIVED_KEYS;
$stored['PROVISIONED_TAP'] = V::PROVISIONED_TAP;

$mismatches = 0;

foreach ($generated as $name => $value) {
    $same = $stored[$name] === $value;
    $mismatches += $same ? 0 : 1;
    echo ($same ? 'ok    ' : 'DIFF  '), $name, ' = ', json_encode($value), PHP_EOL;
}

echo $mismatches === 0 ? 'All stored vectors match the reference.' : "{$mismatches} stored vector(s) differ.", PHP_EOL;

exit($mismatches === 0 ? 0 : 1);
