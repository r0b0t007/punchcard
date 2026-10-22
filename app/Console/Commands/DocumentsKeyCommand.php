<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Verification\DocumentCipher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sets DOCUMENTS_KEY in the environment file (CHW-145), as key:generate
 * sets APP_KEY: the key the verification documents are encrypted with
 * (DocumentCipher). A usable key already set is kept, so a rerun never makes
 * its documents unreadable; replace one by hand, moving the old one to
 * DOCUMENTS_PREVIOUS_KEYS. A value that isn't one ("", changeme) is
 * replaced. Prints no key.
 */
#[Signature('punchcard:documents-key')]
#[Description('Set DOCUMENTS_KEY in .env, the key verification documents are encrypted with (kept if set)')]
class DocumentsKeyCommand extends Command
{
    public function handle(): int
    {
        $file = $this->laravel->environmentFilePath();

        if (! is_file($file)) {
            $this->error('No environment file at '.$file.'.');

            return self::FAILURE;
        }

        $env = (string) file_get_contents($file);

        if (preg_match('/^DOCUMENTS_KEY=["\']?([^"\'\s]*)/m', $env, $set) === 1 && DocumentCipher::isKey($set[1])) {
            $this->info('DOCUMENTS_KEY is already set: kept.');

            return self::SUCCESS;
        }

        $line = 'DOCUMENTS_KEY=base64:'.base64_encode(random_bytes(32));
        $env = preg_match('/^DOCUMENTS_KEY=.*$/m', $env) === 1
            ? (string) preg_replace('/^DOCUMENTS_KEY=.*$/m', $line, $env, 1)
            : rtrim($env, "\n")."\n".$line."\n";

        file_put_contents($file, $env);
        $this->info('DOCUMENTS_KEY set.');

        return self::SUCCESS;
    }
}
