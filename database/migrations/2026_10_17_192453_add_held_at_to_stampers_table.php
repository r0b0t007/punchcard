<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's hold on a stamper (CHW-36): the admin disables a stamper
 * whose tag is suspected copied until its keys are changed
 * (docs/runbooks/stamper-keys.md). Since owners can switch their stampers on
 * and off (B12), the hold keeps them from switching that one back on before
 * the re-key: set by the admin's disable, released by the admin's enable or
 * by recording the re-key. A held stamper is always disabled (Postgres check).
 * Until now only the platform could disable a stamper, so every one disabled
 * today is held: one mid re-key stays off for its owner too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stampers', function (Blueprint $table): void {
            $table->timestamp('held_at')->nullable();
        });

        DB::table('stampers')->where('status', 'disabled')->whereNull('unassigned_at')->update(['held_at' => now()]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table stampers add constraint stampers_held_check check (held_at is null or status = 'disabled')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table stampers drop constraint stampers_held_check');
        }

        Schema::table('stampers', function (Blueprint $table): void {
            $table->dropColumn('held_at');
        });
    }
};
