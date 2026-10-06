<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Redeeming a reward with a tap (CHW-26).
 *
 * - rewards.redeem_window_until: the customer's 60 s redeem window, opened by
 *   "Redeem now"; the next verified tap inside it redeems the reward
 *   (RedeemReward). Not an outcome: it closes again on its own. No foreign
 *   key, so SQLite does not rebuild the table and drop its trigger.
 * - taps.reward_id and the status "redeemed": a tap that redeemed a reward
 *   instead of stamping, and which one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table): void {
            $table->timestamp('redeem_window_until')->nullable()->after('redeemed_location_id');
        });

        Schema::table('taps', function (Blueprint $table): void {
            $table->foreignId('reward_id')->nullable()->after('stamp_event_id')->constrained()->noActionOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table taps
                    drop constraint taps_status_check,
                    add constraint taps_status_check check (status in ('pending', 'stamped', 'redeemed', 'rejected', 'expired')),
                    add constraint taps_redeemed_check check (status <> 'redeemed' or reward_id is not null)
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table taps
                    drop constraint if exists taps_redeemed_check,
                    drop constraint taps_status_check,
                    add constraint taps_status_check check (status in ('pending', 'stamped', 'rejected', 'expired'))
            SQL);
        }

        Schema::table('taps', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reward_id');
        });

        Schema::table('rewards', function (Blueprint $table): void {
            $table->dropColumn('redeem_window_until');
        });
    }
};
