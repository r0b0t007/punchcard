<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Stampers\MoveStamper;
use App\Console\Commands\Concerns\FindsStamperSite;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Moves a tag to another location or business (CHW-138): its assignment
 * ends and a new one starts; the tag keeps its counter and keys
 * (docs/runbooks/stamper-keys.md). Prints the assignment, never key material.
 */
#[Signature('punchcard:stamper:move
    {uid : The tag uid, as the reader prints it}
    {business : The business slug or id it moves to}
    {--location= : The location id (needed when the business has several)}
    {--label= : A new name for the stamper (it keeps its own otherwise)}')]
#[Description('Move an NFC tag to another location or business')]
class StamperMoveCommand extends Command
{
    use FindsStamperSite;

    public function handle(TenantContext $context, MoveStamper $moveStamper): int
    {
        return $this->onStamperSite(
            $context,
            $moveStamper->handle(...),
            fn (Stamper $stamper, string $where): string => "Moved tag {$stamper->tag->uid} to stamper #{$stamper->id} at {$where}; its counter and keys are unchanged.",
        );
    }
}
