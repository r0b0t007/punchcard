<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who armed a stamper (CHW-29, counter mode): an armed tap gives several
 * stamps on a staff member's word, so it carries their name, from the
 * arming (stampers.armed_by) to the tap (taps.armed_by) to the stamp it
 * gives (stamp_events.staff_id, which outlives the tap log). The foreign
 * keys are Postgres only: on SQLite adding one rebuilds the table, which
 * drops the stampers' triggers. A deleted account leaves the column empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['stampers', 'taps'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('armed_by')->nullable();
                $blueprint->index('armed_by');
            });
        }

        // The counter's "last tap" per stamper (DescribeCounter) reads one row, not the stamper's whole history.
        Schema::table('stamp_events', function (Blueprint $table): void {
            $table->index(['stamper_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table stampers add constraint stampers_armed_by_foreign foreign key (armed_by) references users (id) on delete set null');
            DB::statement('alter table taps add constraint taps_armed_by_foreign foreign key (armed_by) references users (id) on delete set null');
            // Only an arming has someone who armed it.
            DB::statement('alter table stampers add constraint stampers_armed_by_check check (armed_by is null or armed_qty is not null)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table stampers drop constraint stampers_armed_by_check');
            DB::statement('alter table taps drop constraint taps_armed_by_foreign');
            DB::statement('alter table stampers drop constraint stampers_armed_by_foreign');
        }

        Schema::table('stamp_events', function (Blueprint $table): void {
            $table->dropIndex(['stamper_id', 'created_at']);
        });

        foreach (['stampers', 'taps'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropIndex(['armed_by']);
                $blueprint->dropColumn('armed_by');
            });
        }
    }
};
