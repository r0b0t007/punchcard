<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A franchise's cards show the organization's brand only (CHW-135): a colour
 * a card kept from before is dropped, as its next save in the builder would
 * drop it. Nothing to put back: CardBrand never shows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('loyalty_cards')
            ->whereNotNull('colors')
            ->whereIn('organization_id', DB::table('organizations')->select('id')->where('type', 'franchise'))
            ->update(['colors' => null]);
    }

    public function down(): void {}
};
