<?php

/**
 * Reference implementation of NTAG 424 DNA SUN verification (NXP AN12196),
 * AES-128 mode, encrypted PICCData + CMAC over an empty MAC input.
 * Verified against the AN12196 test vector (see SKILL.md). Plain PHP + ext-openssl,
 * no framework, so it can be run directly: php reference.php
 *
 * Production code belongs in app/Actions/Stamps and app/Support/Nfc with Pest tests;
 * port this, never include this file from app code. tests/Support/generate-sun-vectors.php
 * uses it to produce the stored test vectors independently of the app.
 */
final class SunReference
{
    /** AES-128 ECB single block, no padding. */
    private static function aesEcb(string $key, string $block): string
    {
        return openssl_encrypt($block, 'aes-128-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    }

    /**
     * RFC 4493 subkeys K1 and K2: double L = AES(key, 0^128) in GF(2^128), then double again.
     *
     * @return array{0: string, 1: string}
     */
    private static function subkeys(string $key): array
    {
        $double = static function (string $in): string {
            $out = '';
            $carry = 0;
            for ($i = 15; $i >= 0; $i--) {
                $b = ord($in[$i]);
                $out = chr((($b << 1) & 0xFF) | $carry).$out;
                $carry = ($b >> 7) & 1;
            }
            if (ord($in[0]) & 0x80) {
                $out[15] = chr(ord($out[15]) ^ 0x87);
            }

            return $out;
        };
        $k1 = $double(self::aesEcb($key, str_repeat("\0", 16)));

        return [$k1, $double($k1)];
    }

    /** RFC 4493 AES-CMAC. */
    public static function cmac(string $key, string $message): string
    {
        [$k1, $k2] = self::subkeys($key);

        $n = max(1, (int) ceil(strlen($message) / 16));
        $complete = strlen($message) > 0 && strlen($message) % 16 === 0;
        $last = substr($message, ($n - 1) * 16);
        $last = $complete ? ($last ^ $k1) : (str_pad($last."\x80", 16, "\0") ^ $k2);

        $x = str_repeat("\0", 16);
        for ($i = 0; $i < $n - 1; $i++) {
            $x = self::aesEcb($key, $x ^ substr($message, $i * 16, 16));
        }

        return self::aesEcb($key, $x ^ $last);
    }

    /**
     * NXP AN10922 AES-128 key diversification, written out literally:
     * D = 0x01 || M (M is 1..31 bytes), padded with 80 00.. to 32 bytes and the
     * second block XORed with K2 (K1 if D is already 32 bytes), then AES-CBC-MAC
     * with a zero IV; the last block is the diversified key.
     */
    public static function an10922(string $key, string $m): string
    {
        if ($m === '' || strlen($m) > 31) {
            throw new InvalidArgumentException('AN10922 input M must be 1 to 31 bytes');
        }
        [$k1, $k2] = self::subkeys($key);

        $d = "\x01".$m;
        $padded = strlen($d) < 32;
        if ($padded) {
            $d = str_pad($d."\x80", 32, "\0");
        }
        $d = substr($d, 0, 16).(substr($d, 16) ^ ($padded ? $k2 : $k1));

        return substr(openssl_encrypt($d, 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16)), 16);
    }

    /**
     * @return array{uid: string, counter: int}
     */
    public static function verify(string $piccDataHex, string $cmacHex, string $metaReadKey, string $fileReadKey): array
    {
        $picc = hex2bin($piccDataHex);
        if ($picc === false || strlen($picc) !== 16) {
            throw new InvalidArgumentException('picc_data must be 16 bytes hex');
        }
        $plain = openssl_decrypt($picc, 'aes-128-cbc', $metaReadKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
        $tag = ord($plain[0]);
        if (($tag & 0xC0) !== 0xC0 || ($tag & 0x0F) !== 7) {
            throw new RuntimeException('PICCDataTag does not mirror a 7-byte UID and counter');
        }
        $uid = substr($plain, 1, 7);
        $ctrLe = substr($plain, 8, 3);
        $counter = unpack('V', $ctrLe."\0")[1];

        $sv2 = "\x3C\xC3\x00\x01\x00\x80".$uid.$ctrLe;
        $sessionKey = self::cmac($fileReadKey, $sv2);
        $full = self::cmac($sessionKey, '');
        $truncated = '';
        for ($i = 1; $i < 16; $i += 2) {
            $truncated .= $full[$i];
        }

        $given = hex2bin($cmacHex);
        if ($given === false || ! hash_equals($truncated, $given)) {
            throw new RuntimeException('CMAC mismatch');
        }

        return ['uid' => strtoupper(bin2hex($uid)), 'counter' => $counter];
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    $zero = str_repeat("\0", 16);
    $r = SunReference::verify('EF963FF7828658A599F3041510671E88', '94EED9EE65337086', $zero, $zero);
    $ok = $r['uid'] === '04DE5F1EACC040' && $r['counter'] === 61;
    // RFC 4493 example 1: empty message
    $rfc = bin2hex(SunReference::cmac(hex2bin('2b7e151628aed2a6abf7158809cf4f3c'), ''));
    $ok = $ok && $rfc === 'bb1d6929e95937287fa37d129b756746';
    // NXP AN10922 AES-128 example: master key, M = UID || AID || system identifier
    $div = strtoupper(bin2hex(SunReference::an10922(hex2bin('00112233445566778899AABBCCDDEEFF'), hex2bin('04782E21801D803042F54E585020416275'))));
    $ok = $ok && $div === 'A8DD63A3B89D54B37CA802473FDA9175';
    echo json_encode($r), ' rfc4493=', $rfc, ' an10922=', $div, PHP_EOL, $ok ? 'OK' : 'FAIL', PHP_EOL;
    exit($ok ? 0 : 1);
}
