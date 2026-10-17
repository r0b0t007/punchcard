<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The staff member's scan a reward was redeemed with (CHW-30), part of its
 * redemption record like redeemed_by: a retry with that scan gets this
 * redemption back, even after the scan ran out. Null when redeemed by a tap.
 * Scans are pruned a day after they run out, which nulls it.
 *
 * The foreign key is Postgres only: SQLite can add a constrained column only
 * by rebuilding the table, which would drop the rewards_outcome_final trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table): void {
            $table->ulid('redeemed_scan_id')->nullable()->index();
        });

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('rewards', function (Blueprint $table): void {
                $table->foreign('redeemed_scan_id')->references('id')->on('counter_scans')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('rewards', function (Blueprint $table): void {
                $table->dropForeign(['redeemed_scan_id']);
            });
        }

        Schema::table('rewards', function (Blueprint $table): void {
            $table->dropIndex(['redeemed_scan_id']);
            $table->dropColumn('redeemed_scan_id');
        });
    }
};
