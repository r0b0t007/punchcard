<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Telling the customer staff redeemed their reward with a scan of their QR
 * (CHW-228, 14a and 14b).
 *
 * - redeemed_by_scan: set with the redemption (RedeemReward), and kept: the
 *   scan itself is pruned a day later, which nulls redeemed_scan_id.
 * - redeem_notice_at: when the customer was told, by a push a phone's push
 *   service accepted, or by Got it on My cards' notice. Null on a scan
 *   redemption: My cards still tells them.
 *
 * Scan redemptions made before count as told: they were at the counter, and a
 * "Not you?" weeks later would only alarm them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table): void {
            $table->boolean('redeemed_by_scan')->default(false);
            $table->timestamp('redeem_notice_at')->nullable();
        });

        DB::table('rewards')->whereNotNull('redeemed_scan_id')
            ->update(['redeemed_by_scan' => true, 'redeem_notice_at' => DB::raw('redeemed_at')]);
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $table): void {
            $table->dropColumn(['redeemed_by_scan', 'redeem_notice_at']);
        });
    }
};
