<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Stampers\RecordRekey;
use App\Actions\Stampers\StamperRefused;
use App\Models\Stamper;
use App\Support\Nfc\TagUid;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Records a tag's new key version once it was re-provisioned off-server
 * (CHW-138, docs/runbooks/stamper-keys.md, "Rotating one stamper's keys").
 * The admin names the version the tag now has, never "the next one": a
 * repeated run (a dropped SSH session) is refused, instead of moving the tag
 * past the keys it holds. Asks which keys changed first, and never prints
 * key material.
 */
#[Signature('punchcard:tag:rekeyed
    {uid : The tag uid, as the reader prints it}
    {version : The key version the tag was re-provisioned to}
    {--force : Record without asking}')]
#[Description('Record that an NFC tag was re-provisioned to a new key version')]
class TagRekeyedCommand extends Command
{
    public function handle(TenantContext $context, RecordRekey $recordRekey): int
    {
        try {
            $uid = TagUid::normalise((string) $this->argument('uid'));
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::FAILURE;
        }

        $version = (string) $this->argument('version');

        if (! ctype_digit($version) || (int) $version < 2) {
            $this->error('The version is the key version the tag now has: a whole number, 2 or more.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Have keys 2, 3, 4 and then 0 all been changed on tag {$uid} to key version {$version}?")) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        try {
            $tag = $recordRekey->handle($uid, from: (int) $version - 1);
        } catch (StamperRefused $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $stamperId = $context->bypass(fn (): mixed => Stamper::query()->current()->where('nfc_tag_id', $tag->id)->value('id'));

        $this->info("Tag {$uid} is now at key version {$tag->key_version}; its counter ({$tag->last_counter}) is unchanged.");
        $this->line($stamperId === null
            ? 'Assign it when it is back in service.'
            : "Re-enable it with punchcard:stamper:enable {$uid} (stamper #{$stamperId}) and test one tap.");

        return self::SUCCESS;
    }
}
