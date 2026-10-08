<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Stampers\SetStamperStatus;
use App\Console\Commands\Concerns\SetsStamperStatus;
use App\Enums\StamperStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Re-enables a tag's stamper after a re-key (CHW-138, docs/runbooks/stamper-keys.md). */
#[Signature('punchcard:stamper:enable {uid : The tag uid, as the reader prints it}')]
#[Description('Re-enable an NFC tag\'s stamper')]
class StamperEnableCommand extends Command
{
    use SetsStamperStatus;

    public function handle(SetStamperStatus $setStamperStatus): int
    {
        return $this->setStatus($setStamperStatus, StamperStatus::Active);
    }
}
