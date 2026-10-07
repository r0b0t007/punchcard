<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Stampers\RegisterStamper;
use App\Actions\Stampers\StamperRefused;
use App\Console\Commands\Concerns\FindsStamperSite;
use App\Models\Business;
use App\Models\NfcTag;
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
    {business : The business id or slug}
    {--location= : The location id (needed when the business has several)}
    {--label= : A name for the stamper, for example Counter}')]
#[Description('Register an NFC tag and assign it to a location of a business')]
class StamperRegisterCommand extends Command
{
    use FindsStamperSite;

    public function handle(TenantContext $context, RegisterStamper $registerStamper): int
    {
        $business = $this->namedBusiness($context);

        if (! $business instanceof Business) {
            $this->error('No business with that id or slug.');

            return self::FAILURE;
        }

        $location = $this->namedLocation($context);

        if ($location === false) {
            $this->error('No location with that id.');

            return self::FAILURE;
        }

        $label = $this->option('label');

        try {
            $stamper = $registerStamper->handle((string) $this->argument('uid'), $business, $location, is_string($label) ? $label : null);
        } catch (StamperRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $tag = $context->bypass(fn (): NfcTag => NfcTag::query()->findOrFail($stamper->nfc_tag_id));
        $this->info("Registered tag {$tag->uid} (key version {$tag->key_version}) as stamper #{$stamper->id} at {$this->whereStamperIs($context, $business, $stamper)}.");

        return self::SUCCESS;
    }
}
