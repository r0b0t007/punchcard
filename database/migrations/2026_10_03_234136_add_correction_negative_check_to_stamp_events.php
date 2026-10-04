<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A correction only takes stamps back (CHW-24): restoring missed stamps is a
 * manual stamp, held to the cooldown and the daily cap. With the existing
 * stamp_events_negative_check (qty > 0 or a correction), a correction is
 * exactly the negative events. Postgres only, like the other CHECKs;
 * StampEvent's insert guard says the same on every driver. NOT VALID: it
 * holds for every new row without checking older ones, which the append-only
 * ledger could not fix anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table stamp_events add constraint stamp_events_correction_check check (source <> 'correction' or qty < 0) not valid");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table stamp_events drop constraint stamp_events_correction_check');
        }
    }
};
