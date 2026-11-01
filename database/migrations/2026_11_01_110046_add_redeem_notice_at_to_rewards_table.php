<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the customer was told staff redeemed their reward with a scan of
 * their QR (CHW-228, 14a and 14b): a push a phone's push service accepted, or
 * Got it on My cards' notice. Null on such a reward: My cards still tells
 * them. Never set for a reward they redeemed with their own tap.
 *
 * Scan redemptions made before count as told: they were at the counter, and a
 * "Not you?" weeks later would only alarm them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table): void {
            $table->timestamp('redeem_notice_at')->nullable();
        });

        DB::table('rewards')->whereNotNull('redeemed_scan_id')->update(['redeem_notice_at' => DB::raw('redeemed_at')]);
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $table): void {
            $table->dropColumn('redeem_notice_at');
        });
    }
};
