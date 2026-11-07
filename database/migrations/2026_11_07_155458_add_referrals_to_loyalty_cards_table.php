<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refer-a-friend on a card (CHW-47): off until its owner turns it on, and
 * the most referral stamps a customer earns from it in a month, 1 to 10 (a
 * Postgres CHECK; CardRequest says the same on every driver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table): void {
            $table->boolean('referrals_enabled')->default(false);
            $table->unsignedTinyInteger('referral_monthly_cap')->default(3);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table loyalty_cards add constraint loyalty_cards_referral_cap_check check (referral_monthly_cap between 1 and 10)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table loyalty_cards drop constraint loyalty_cards_referral_cap_check');
        }

        Schema::table('loyalty_cards', function (Blueprint $table): void {
            $table->dropColumn(['referrals_enabled', 'referral_monthly_cap']);
        });
    }
};
