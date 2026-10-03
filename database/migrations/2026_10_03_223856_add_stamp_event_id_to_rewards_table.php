<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The stamp that unlocked a reward (CHW-24): AddStamps returns a replayed
 * stamp's rewards from it, and the owner's history can show it. Null for
 * rewards created otherwise (imports, earlier rows). stamp_events is
 * append-only, so the reference never dangles; it never changes (Reward).
 *
 * The foreign key is Postgres only: SQLite can add a constrained column only
 * by rebuilding the table, which would drop the rewards_outcome_final trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table): void {
            $table->unsignedBigInteger('stamp_event_id')->nullable()->index();
        });

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('rewards', function (Blueprint $table): void {
                $table->foreign('stamp_event_id')->references('id')->on('stamp_events')->noActionOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('rewards', function (Blueprint $table): void {
                $table->dropForeign(['stamp_event_id']);
            });
        }

        Schema::table('rewards', function (Blueprint $table): void {
            $table->dropIndex(['stamp_event_id']);
            $table->dropColumn('stamp_event_id');
        });
    }
};
