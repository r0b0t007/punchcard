<?php

declare(strict_types=1);

namespace App\Support\Nfc;

use LogicException;

/**
 * Builds the `e` and `c` a provisioned tag would emit for a UID and counter
 * (NXP AN12196), from the configured master key: for tests, Playwright and
 * `php artisan punchcard:fake-tap`. Anyone holding it can stamp from home, so
 * it runs only in local and testing (any other environment may hold the real
 * master key).
 */
final readonly class FakeTap
{
    /** The only environments it runs in: staging may share the real master key. */
    public const array ENVIRONMENTS = ['local', 'testing'];

    public function __construct(private KeyDiversifier $keys) {}

    /**
     * @param  int  $keyVersion  the tag's own key version (its file read key)
     * @param  int|null  $metaVersion  the system meta read key version; the configured one by default
     * @return array{e: string, c: string}
     */
    public function build(string $uid, int $counter, int $keyVersion = 1, ?int $metaVersion = null): array
    {
        if (! app()->environment(self::ENVIRONMENTS)) {
            throw new LogicException('Fake taps are built only in local and testing, never in production or staging.');
        }

        $metaVersion ??= (int) config('punchcard.nfc.key_version');
        $counterBytes = substr(pack('V', $counter), 0, 3);
        $plain = "\xC7".hex2bin($uid).$counterBytes.str_repeat("\xA5", 5);
        $e = (string) openssl_encrypt($plain, 'aes-128-cbc', $this->keys->metaReadKey($metaVersion), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));

        $c = SunVerifier::sessionMac($this->keys->fileReadKey($uid, $keyVersion), $uid, $counter);

        return ['e' => strtoupper(bin2hex($e)), 'c' => strtoupper(bin2hex($c))];
    }
}
