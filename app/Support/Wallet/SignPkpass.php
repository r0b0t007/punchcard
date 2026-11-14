<?php

declare(strict_types=1);

namespace App\Support\Wallet;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * A .pkpass (CHW-38): the pass's files, manifest.json (the SHA-1 of each), and
 * signature, a detached PKCS#7 signature of the manifest made with the Pass
 * Type ID certificate (.p12, AppleWalletConfig) and carrying Apple's WWDR
 * intermediate, in DER, as Wallet checks it. Zipped in a temporary file under
 * storage/app/private/tmp, read back and removed; nothing about the
 * certificate is ever written there.
 */
final class SignPkpass
{
    /**
     * @param  array<string, string>  $files  file name => bytes (pass.json, the images)
     */
    public static function handle(array $files): string
    {
        $manifest = json_encode(array_map(sha1(...), $files), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $files['manifest.json'] = $manifest;
        $files['signature'] = self::signature($manifest);

        return self::zip($files);
    }

    /** The manifest's detached PKCS#7 signature, in DER. */
    public static function signature(string $manifest): string
    {
        $certificate = @file_get_contents(AppleWalletConfig::certPath());

        if (! is_string($certificate) || ! openssl_pkcs12_read($certificate, $parts, AppleWalletConfig::certPassword())) {
            throw new RuntimeException('The Apple Pass Type ID certificate can\'t be read: check punchcard.wallet.apple.cert_path and its password.');
        }

        if (! is_file(AppleWalletConfig::wwdrCertPath())) {
            throw new RuntimeException('Apple\'s WWDR certificate can\'t be read: check punchcard.wallet.apple.wwdr_cert_path.');
        }

        $directory = self::temporaryDirectory();
        $in = tempnam($directory, 'manifest');
        $out = tempnam($directory, 'signed');

        try {
            file_put_contents((string) $in, $manifest);

            if (! openssl_pkcs7_sign((string) $in, (string) $out, $parts['cert'], $parts['pkey'], [], PKCS7_BINARY | PKCS7_DETACHED, AppleWalletConfig::wwdrCertPath())) {
                throw new RuntimeException('The pass manifest could not be signed: '.(openssl_error_string() ?: 'unknown OpenSSL error').'.');
            }

            return self::der((string) file_get_contents((string) $out));
        } finally {
            @unlink((string) $in);
            @unlink((string) $out);
        }
    }

    /** The signature out of the S/MIME message openssl_pkcs7_sign writes: its base64 part, decoded. */
    private static function der(string $smime): string
    {
        if (preg_match('/Content-Type: application\/(?:x-)?pkcs7-signature.*?\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/s', $smime, $part) !== 1) {
            throw new RuntimeException('The signed pass manifest has no PKCS#7 signature part.');
        }

        $der = base64_decode((string) preg_replace('/\s+/', '', $part[1]), true);

        if (! is_string($der) || $der === '') {
            throw new RuntimeException('The PKCS#7 signature of the pass manifest is not valid base64.');
        }

        return $der;
    }

    /**
     * @param  array<string, string>  $files
     */
    private static function zip(array $files): string
    {
        $path = tempnam(self::temporaryDirectory(), 'pkpass');
        $zip = new ZipArchive;

        try {
            if ($zip->open((string) $path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The pass archive could not be created.');
            }

            foreach ($files as $name => $bytes) {
                $zip->addFromString($name, $bytes);
            }

            $zip->close();

            return (string) file_get_contents((string) $path);
        } finally {
            @unlink((string) $path);
        }
    }

    private static function temporaryDirectory(): string
    {
        $directory = storage_path('app/private/tmp');
        File::ensureDirectoryExists($directory);

        return $directory;
    }
}
