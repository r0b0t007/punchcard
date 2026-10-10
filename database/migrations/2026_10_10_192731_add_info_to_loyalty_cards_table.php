<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The card builder (CHW-32): a card's info for its customers (opening hours,
 * phone, links), shown with the card. On Postgres, the stamp style is one of
 * those the card can draw (resources/js/components/loyalty-card/stamp.tsx);
 * a stored unknown one goes back to the default dot.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array STAMP_STYLES = ['dot', 'ring', 'check', 'heart', 'star', 'logo'];

    public function up(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table): void {
            $table->json('info')->nullable()->after('terms');
        });

        DB::table('loyalty_cards')->whereNotNull('stamp_style')->whereNotIn('stamp_style', self::STAMP_STYLES)->update(['stamp_style' => null]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table loyalty_cards add constraint loyalty_cards_stamp_style_check check (stamp_style is null or stamp_style in ('".implode("', '", self::STAMP_STYLES)."'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table loyalty_cards drop constraint if exists loyalty_cards_stamp_style_check');
        }

        Schema::table('loyalty_cards', function (Blueprint $table): void {
            $table->dropColumn('info');
        });
    }
};
