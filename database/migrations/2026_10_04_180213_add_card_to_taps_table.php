<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The card a tap was judged on, and its stamps right after a stamp (CHW-25):
 * the result page shows that card and that moment, not whichever card the
 * customer holds there now or its live count. Set by ApplyTap; null for a tap
 * that never reached a card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taps', function (Blueprint $table): void {
            $table->foreignId('card_id')->nullable()->after('location_id')->constrained('loyalty_cards')->noActionOnDelete();
            $table->unsignedInteger('card_stamps')->nullable()->after('card_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table taps add constraint taps_card_stamps_check check (card_stamps is null or (card_stamps >= 0 and card_id is not null))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table taps drop constraint if exists taps_card_stamps_check');
        }

        Schema::table('taps', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('card_id');
            $table->dropColumn('card_stamps');
        });
    }
};
