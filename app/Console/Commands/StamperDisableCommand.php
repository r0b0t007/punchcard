<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Stampers\SetStamperStatus;
use App\Console\Commands\Concerns\SetsStamperStatus;
use App\Enums\StamperStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Disables a tag's stamper before a re-key (CHW-138, docs/runbooks/stamper-keys.md): it refuses every tap. */
#[Signature('punchcard:stamper:disable {uid : The tag uid, as the reader prints it}')]
#[Description('Disable an NFC tag\'s stamper, so it refuses every tap')]
class StamperDisableCommand extends Command
{
    use SetsStamperStatus;

    public function handle(SetStamperStatus $setStamperStatus): int
    {
        return $this->setStatus($setStamperStatus, StamperStatus::Disabled);
    }
}
