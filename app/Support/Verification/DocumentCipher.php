<?php

declare(strict_types=1);

namespace App\Support\Verification;

use Illuminate\Contracts\Encryption\DecryptException;
use RuntimeException;

/**
 * The verification documents' own key (CHW-145): never APP_KEY, which signs
 * cookies, sessions and links, so one leak of it exposes no ID scan. Set as
 * DOCUMENTS_KEY ("base64:" and 32 random bytes; punchcard:documents-key); a
 * rotated one goes on in DOCUMENTS_PREVIOUS_KEYS (comma-separated) until
 * every file under it is deleted or encrypted again. Each document keeps the
 * id of the key it was encrypted with (keyId).
 *
 * AES-256-GCM over the raw bytes: a version byte, the 12-byte nonce, the
 * 16-byte tag, then the ciphertext, the size of the file. A changed file
 * fails to decrypt instead of reading wrong.
 */
final readonly class DocumentCipher
{
    private const string CIPHER = 'aes-256-gcm';

    private const string VERSION = "\x01";

    public function encrypt(string $bytes): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($bytes, self::CIPHER, $this->key((string) config('punchcard.documents.key')), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        if (! is_string($ciphertext)) {
            throw new RuntimeException('The document could not be encrypted.');
        }

        return self::VERSION.$nonce.$tag.$ciphertext;
    }

    /** The bytes, read with the current key or one of the previous ones. */
    public function decrypt(string $stored): string
    {
        if (strlen($stored) < 29 || $stored[0] !== self::VERSION) {
            throw new DecryptException('This is not a verification document.');
        }

        [$nonce, $tag, $ciphertext] = [substr($stored, 1, 12), substr($stored, 13, 16), substr($stored, 29)];

        foreach ($this->keys() as $key) {
            $bytes = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);

            if (is_string($bytes)) {
                return $bytes;
            }
        }

        throw new DecryptException('The document could not be decrypted with DOCUMENTS_KEY or DOCUMENTS_PREVIOUS_KEYS.');
    }

    /** The current key's id: the first 16 hex characters of its SHA-256, never the key. */
    public function keyId(): string
    {
        return self::idOf((string) config('punchcard.documents.key'));
    }

    /** A configured key's id, as keyId() gives it (punchcard:documents:check). */
    public static function idOf(string $configured): string
    {
        return substr(hash('sha256', (new self)->key($configured)), 0, 16);
    }

    /** @return list<string> the current key first, then the previous ones that read as keys (a malformed one is reported, and skipped) */
    private function keys(): array
    {
        $keys = [$this->key((string) config('punchcard.documents.key'))];

        foreach (self::previousKeys() as $previous) {
            try {
                $keys[] = $this->key($previous);
            } catch (DocumentKeyMissing) {
                once(fn () => report(new DocumentKeyMissing('A key in DOCUMENTS_PREVIOUS_KEYS is not "base64:" and 32 bytes: skipped.')));
            }
        }

        return $keys;
    }

    /**
     * DOCUMENTS_PREVIOUS_KEYS as written, comma-separated, each trimmed, by its place in the list (from 1); empty entries
     * are left out but keep their place, so a check can name the entry an operator sees.
     *
     * @return array<int, string>
     */
    public static function previousKeys(): array
    {
        $keys = [];

        foreach (explode(',', (string) config('punchcard.documents.previous_keys')) as $index => $key) {
            if (trim($key) !== '') {
                $keys[$index + 1] = trim($key);
            }
        }

        return $keys;
    }

    /** Whether a configured value is a usable key ("base64:" and 32 bytes). */
    public static function isKey(string $configured): bool
    {
        try {
            (new self)->key($configured);

            return true;
        } catch (DocumentKeyMissing) {
            return false;
        }
    }

    private function key(string $configured): string
    {
        $key = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : false;

        if (! is_string($key) || strlen($key) !== 32) {
            throw new DocumentKeyMissing('Set DOCUMENTS_KEY to "base64:" and 32 random bytes (php artisan punchcard:documents-key).');
        }

        return $key;
    }
}
