<?php

declare(strict_types=1);

namespace App\Support\Nfc;

use LogicException;

/**
 * Builds the `e` and `c` a provisioned tag would emit for a UID and counter
 * (NXP AN12196), from the configured master key: for tests, Playwright and
 * `php artisan punchcard:fake-tap`. Anyone holding it can stamp from home, so
 * it refuses to run in production.
 */
final readonly class FakeTap
{
    public function __construct(private KeyDiversifier $keys) {}

    /**
     * @param  int  $keyVersion  the tag's own key version (its file read key)
     * @param  int|null  $metaVersion  the system meta read key version; the configured one by default
     * @return array{e: string, c: string}
     */
    public function build(string $uid, int $counter, int $keyVersion = 1, ?int $metaVersion = null): array
    {
        if (app()->isProduction()) {
            throw new LogicException('Fake taps are never built in production.');
        }

        $metaVersion ??= (int) config('punchcard.nfc.key_version');
        $counterBytes = substr(pack('V', $counter), 0, 3);
        $plain = "\xC7".hex2bin($uid).$counterBytes.str_repeat("\xA5", 5);
        $e = (string) openssl_encrypt($plain, 'aes-128-cbc', $this->keys->metaReadKey($metaVersion), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));

        $sessionKey = AesCmac::compute($this->keys->fileReadKey($uid, $keyVersion), "\x3C\xC3\x00\x01\x00\x80".hex2bin($uid).$counterBytes);
        $full = AesCmac::compute($sessionKey, '');
        $c = '';

        for ($i = 1; $i < 16; $i += 2) {
            $c .= $full[$i];
        }

        return ['e' => strtoupper(bin2hex($e)), 'c' => strtoupper(bin2hex($c))];
    }
}
