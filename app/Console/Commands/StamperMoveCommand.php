<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Stampers\MoveStamper;
use App\Actions\Stampers\StamperRefused;
use App\Console\Commands\Concerns\FindsStamperSite;
use App\Models\Business;
use App\Models\NfcTag;
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
    {business : The business id or slug it moves to}
    {--location= : The location id (needed when the business has several)}
    {--label= : A name for the stamper, for example Counter}')]
#[Description('Move an NFC tag to another location or business')]
class StamperMoveCommand extends Command
{
    use FindsStamperSite;

    public function handle(TenantContext $context, MoveStamper $moveStamper): int
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
            $stamper = $moveStamper->handle((string) $this->argument('uid'), $business, $location, is_string($label) ? $label : null);
        } catch (StamperRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $uid = $context->bypass(fn (): string => NfcTag::query()->findOrFail($stamper->nfc_tag_id)->uid);
        $this->info("Moved tag {$uid} to stamper #{$stamper->id} at {$this->whereStamperIs($context, $business, $stamper)}; its counter and keys are unchanged.");

        return self::SUCCESS;
    }
}
