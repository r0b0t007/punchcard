<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Stampers\RetireTag;
use App\Actions\Stampers\SiteName;
use App\Actions\Stampers\StamperRefused;
use App\Models\Stamper;
use App\Support\Nfc\TagUid;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Retires a lost or stolen tag for good and ends its assignment (CHW-138,
 * docs/runbooks/stamper-keys.md). Asks first, since it is one-way.
 */
#[Signature('punchcard:tag:retire
    {uid : The tag uid, as the reader prints it}
    {--force : Retire without asking}')]
#[Description('Retire a lost or stolen NFC tag for good')]
class TagRetireCommand extends Command
{
    public function handle(RetireTag $retireTag): int
    {
        try {
            $uid = TagUid::normalise((string) $this->argument('uid'));
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Retire tag {$uid} for good? It can never be assigned again.")) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        try {
            $ended = $retireTag->handle($uid);
        } catch (StamperRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info($ended instanceof Stamper
            ? "Retired tag {$uid}; its assignment ended (stamper #{$ended->id} at {$ended->business->name}, ".SiteName::of($ended->location).'). Register a new tag for its replacement.'
            : "Retired tag {$uid}; it had no assignment. Register a new tag for its replacement.");

        return self::SUCCESS;
    }
}
