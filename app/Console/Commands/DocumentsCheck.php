<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BusinessDocument;
use App\Support\Tenancy\TenantContext;
use App\Support\Verification\DocumentCipher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Post-deploy check of the verification documents' key (CHW-145,
 * docs/runbooks/verification-documents.md): DOCUMENTS_KEY usable and not
 * APP_KEY, every key in DOCUMENTS_PREVIOUS_KEYS usable, and every stored
 * document encrypted with one of them (its key_id). Without it a bad or
 * lost key only shows when an owner uploads or the admin opens a document.
 * Run after the migrations. Never prints key material.
 */
#[Signature('punchcard:documents:check')]
#[Description('Check the verification documents\' key, and that every stored document has its key configured')]
class DocumentsCheck extends Command
{
    private const string RUNBOOK = 'docs/runbooks/verification-documents.md';

    public function handle(TenantContext $context): int
    {
        $current = (string) config('punchcard.documents.key');
        $previous = array_values(array_filter(array_map(trim(...), explode(',', (string) config('punchcard.documents.previous_keys')))));

        if (! DocumentCipher::isKey($current)) {
            $this->error('DOCUMENTS_KEY is missing or not "base64:" and 32 bytes. Run php artisan punchcard:documents-key ('.self::RUNBOOK.').');

            return self::FAILURE;
        }

        if (hash_equals((string) config('app.key'), $current)) {
            $this->error('DOCUMENTS_KEY is APP_KEY. The documents need a key of their own ('.self::RUNBOOK.').');

            return self::FAILURE;
        }

        foreach ($previous as $position => $key) {
            if (! DocumentCipher::isKey($key)) {
                $this->error('Key '.($position + 1).' in DOCUMENTS_PREVIOUS_KEYS is not "base64:" and 32 bytes ('.self::RUNBOOK.').');

                return self::FAILURE;
            }
        }

        $known = array_map(DocumentCipher::idOf(...), [$current, ...$previous]);
        $unreadable = $context->bypass(fn (): int => BusinessDocument::query()->whereNotIn('key_id', $known)->count());

        if ($unreadable > 0) {
            $this->error($unreadable.' stored documents were encrypted with a key in neither DOCUMENTS_KEY nor DOCUMENTS_PREVIOUS_KEYS, so they can\'t be read. Put that key back in DOCUMENTS_PREVIOUS_KEYS ('.self::RUNBOOK.').');

            return self::FAILURE;
        }

        $this->info('Documents key OK: '.count($previous).' previous, and every stored document\'s key is configured.');

        return self::SUCCESS;
    }
}
