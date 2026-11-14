<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

/**
 * Throwaway Apple Wallet certificates for tests (CHW-38): a CA standing in
 * for Apple's WWDR and a Pass Type ID certificate it signed, as a .p12 with a
 * password, made fresh in a temporary folder and removed after. Nothing is
 * committed, and they never leave the test. A minimal openssl.cnf beside them,
 * as PHP on Windows has none to make keys with.
 */
final readonly class WalletCertificates
{
    public const string PASSWORD = 'test-pass-password';

    private function __construct(public string $directory) {}

    /** Makes them and points config('punchcard.wallet.apple') at them. */
    public static function install(): self
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wallet-test-'.bin2hex(random_bytes(6));

        if (! mkdir($directory) && ! is_dir($directory)) {
            throw new RuntimeException('No temporary folder for the test certificates.');
        }

        file_put_contents($directory.'/openssl.cnf', "[req]\ndistinguished_name = dn\n[dn]\n");
        $options = ['config' => $directory.'/openssl.cnf', 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];

        $caKey = self::key($options);
        $ca = self::sign(openssl_csr_new(['commonName' => 'Test WWDR'], $caKey, $options), null, $caKey, $options, 1);
        $passKey = self::key($options);
        // A serial of its own: the signer is found by issuer and serial, which must not be the CA's.
        $pass = self::sign(openssl_csr_new(['commonName' => 'Pass Type ID: pass.test.punchcard'], $passKey, $options), $ca, $caKey, $options, 2);

        openssl_x509_export_to_file($ca, $directory.'/wwdr.pem');
        openssl_x509_export_to_file($pass, $directory.'/pass.pem');
        openssl_pkcs12_export_to_file($pass, $directory.'/pass.p12', $passKey, self::PASSWORD);

        config(['punchcard.wallet.apple' => [
            'pass_type_id' => 'pass.test.punchcard',
            'team_id' => 'TEAMID1234',
            'cert_path' => $directory.'/pass.p12',
            'cert_password' => self::PASSWORD,
            'wwdr_cert_path' => $directory.'/wwdr.pem',
        ]]);

        return new self($directory);
    }

    public function remove(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function key(array $options): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new($options);

        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('No test key: '.openssl_error_string());
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function sign(mixed $request, ?OpenSSLCertificate $ca, OpenSSLAsymmetricKey $key, array $options, int $serial): OpenSSLCertificate
    {
        $certificate = is_bool($request) ? false : openssl_csr_sign($request, $ca, $key, 1, $options, $serial);

        if (! $certificate instanceof OpenSSLCertificate) {
            throw new RuntimeException('No test certificate: '.openssl_error_string());
        }

        return $certificate;
    }
}
