<?php

declare(strict_types=1);

namespace App\Support\Verification;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * The verification documents' own key (CHW-145): never APP_KEY, which signs
 * cookies, sessions and links, so one leak of it exposes no ID scan. Set as
 * DOCUMENTS_KEY ("base64:" and 32 random bytes); a rotated one goes on in
 * DOCUMENTS_PREVIOUS_KEYS (comma-separated) until every file under it is
 * deleted or encrypted again. Each document keeps the id of the key it was
 * encrypted with (keyId), so the files to re-encrypt can be found. AES-256-GCM:
 * a changed file fails to decrypt instead of reading wrong.
 */
final readonly class DocumentCipher
{
    private const string CIPHER = 'aes-256-gcm';

    public function encrypt(string $bytes): string
    {
        return $this->encrypter()->encryptString($bytes);
    }

    public function decrypt(string $stored): string
    {
        return $this->encrypter()->decryptString($stored);
    }

    /** The current key's id: the first 16 hex characters of its SHA-256, never the key. */
    public function keyId(): string
    {
        return substr(hash('sha256', $this->key((string) config('punchcard.documents.key'))), 0, 16);
    }

    private function encrypter(): Encrypter
    {
        $previous = array_map($this->key(...), array_filter(array_map(trim(...), explode(',', (string) config('punchcard.documents.previous_keys')))));

        return (new Encrypter($this->key((string) config('punchcard.documents.key')), self::CIPHER))->previousKeys($previous);
    }

    private function key(string $configured): string
    {
        $key = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : false;

        if (! is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('Set DOCUMENTS_KEY to "base64:" and 32 random bytes (verification documents, CHW-145).');
        }

        return $key;
    }
}
