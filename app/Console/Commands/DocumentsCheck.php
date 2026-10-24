<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BusinessDocument;
use App\Support\Tenancy\TenantContext;
use App\Support\Verification\DocumentCipher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Post-deploy check of the verification documents' key (CHW-145,
 * docs/runbooks/verification-documents.md): DOCUMENTS_KEY usable, every key
 * in DOCUMENTS_PREVIOUS_KEYS usable, none of them APP_KEY or one of its
 * previous keys, and every stored document encrypted with one of them (its
 * key_id). Says how many documents each previous key still covers, so one
 * at 0 can go. After a lost key, --allow-unreadable warns about the
 * documents nobody can read instead of failing, and the rest still fails.
 * Run after the migrations. Never prints key material.
 */
#[Signature('punchcard:documents:check {--allow-unreadable : After a lost key (see the runbook): warn about documents nobody can read instead of failing}')]
#[Description('Check the verification documents\' key, and that every stored document has its key configured')]
class DocumentsCheck extends Command
{
    private const string RUNBOOK = 'docs/runbooks/verification-documents.md';

    public function handle(TenantContext $context): int
    {
        $current = (string) config('punchcard.documents.key');
        $previous = DocumentCipher::previousKeys();

        if (! DocumentCipher::isKey($current)) {
            $this->error('DOCUMENTS_KEY is missing or not "base64:" and 32 bytes. Run php artisan punchcard:documents-key ('.self::RUNBOOK.').');

            return self::FAILURE;
        }

        foreach ($previous as $place => $key) {
            if (! DocumentCipher::isKey($key)) {
                $this->error('Key '.$place.' in DOCUMENTS_PREVIOUS_KEYS is not "base64:" and 32 bytes ('.self::RUNBOOK.').');

                return self::FAILURE;
            }
        }

        $appKeys = array_map($this->bytes(...), array_filter([(string) config('app.key'), ...(array) config('app.previous_keys')]));

        foreach ([$current, ...$previous] as $key) {
            foreach ($appKeys as $appKey) {
                if (hash_equals($appKey, $this->bytes($key))) {
                    $this->error(($key === $current ? 'DOCUMENTS_KEY' : 'A key in DOCUMENTS_PREVIOUS_KEYS').' is APP_KEY or one of APP_PREVIOUS_KEYS. The documents need a key of their own ('.self::RUNBOOK.').');

                    return self::FAILURE;
                }
            }
        }

        /** @var array<string, int> $counts documents by the id of the key that encrypted them */
        $counts = $context->bypass(fn (): array => BusinessDocument::query()->selectRaw('key_id, count(*) as total')->groupBy('key_id')
            ->pluck('total', 'key_id')->map(fn (mixed $total): int => (int) $total)->all());

        foreach ($previous as $place => $key) {
            $this->line('Previous key '.$place.': '.$this->documents($counts[DocumentCipher::idOf($key)] ?? 0).'.');
        }

        $unreadable = array_diff_key($counts, array_flip(array_map(DocumentCipher::idOf(...), [$current, ...$previous])));

        if ($unreadable !== []) {
            $message = $this->documents(array_sum($unreadable)).' can\'t be read: the key is in neither DOCUMENTS_KEY nor DOCUMENTS_PREVIOUS_KEYS. Put it back in DOCUMENTS_PREVIOUS_KEYS, or see "A lost key" ('.self::RUNBOOK.').';

            if ($this->option('allow-unreadable')) {
                $this->warn($message);
            } else {
                $this->error($message);
            }

            foreach ($unreadable as $keyId => $total) {
                $this->line('  key id '.$keyId.': '.$this->documents($total));
            }

            if (! $this->option('allow-unreadable')) {
                return self::FAILURE;
            }
        }

        $this->info('Documents key OK: '.count($previous).' previous, current key: '.$this->documents($counts[DocumentCipher::idOf($current)] ?? 0).'.');

        return self::SUCCESS;
    }

    /** A key's bytes as Laravel's encrypter reads it: "base64:" decoded, anything else as written. */
    private function bytes(string $configured): string
    {
        return str_starts_with($configured, 'base64:') ? (string) base64_decode(substr($configured, 7), true) : $configured;
    }

    private function documents(int $total): string
    {
        return $total.' '.Str::plural('document', $total);
    }
}
