<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Nfc\FakeTap;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Prints a working tap URL for a stamper's tag, as if a phone had just
 * touched it: for local work and end-to-end tests without hardware. Refuses
 * to run in production (anyone with it could stamp from home).
 */
#[Signature('punchcard:fake-tap {stamper : The stamper id} {--counter= : The counter to use (default: the tag\'s next one)}')]
#[Description('Print a working tap URL for a stamper (local and testing only)')]
class FakeTapCommand extends Command
{
    public function handle(TenantContext $context, FakeTap $fakeTap): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('Fake taps are never built in production.');

            return self::FAILURE;
        }

        $tag = $context->bypass(fn (): ?NfcTag => NfcTag::query()
            ->whereIn('id', Stamper::query()->whereKey($this->argument('stamper'))->select('nfc_tag_id'))
            ->first());

        if (! $tag instanceof NfcTag) {
            $this->error('No stamper with that id.');

            return self::FAILURE;
        }

        $counter = $this->option('counter') !== null ? (int) $this->option('counter') : $tag->last_counter + 1;
        $url = $fakeTap->build($tag->uid, $counter, $tag->key_version);

        $this->line(rtrim((string) config('punchcard.tap.url'), '?').'?'.http_build_query($url));

        return self::SUCCESS;
    }
}
