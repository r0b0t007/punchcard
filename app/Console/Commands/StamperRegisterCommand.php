<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Stampers\RegisterStamper;
use App\Console\Commands\Concerns\FindsStamperSite;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Registers an NFC tag and assigns it to a location of a business (CHW-138),
 * after it was provisioned with keys derived off-server
 * (docs/runbooks/stamper-keys.md). Prints the assignment, never key material.
 */
#[Signature('punchcard:stamper:register
    {uid : The tag uid, as the reader prints it}
    {business : The business slug or id}
    {--location= : The location id (needed when the business has several)}
    {--label= : A name for the stamper, for example Counter}')]
#[Description('Register an NFC tag and assign it to a location of a business')]
class StamperRegisterCommand extends Command
{
    use FindsStamperSite;

    public function handle(TenantContext $context, RegisterStamper $registerStamper): int
    {
        return $this->onStamperSite(
            $context,
            $registerStamper->handle(...),
            fn (Stamper $stamper, string $where): string => "Registered tag {$stamper->tag->uid} (key version {$stamper->tag->key_version}) as stamper #{$stamper->id} at {$where}.",
        );
    }
}
