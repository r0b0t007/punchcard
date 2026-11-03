<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Campaigns may go to the customers going quiet (CHW-45, 'going_quiet');
 * 'quiet_14d' stays for the campaigns made with it. Postgres only, like the
 * other CHECKs; the request and the enum cast say the same on every driver.
 * Rolled back, the old CHECK is NOT VALID: it holds for new rows, and the
 * campaigns already sent to going_quiet stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table campaigns
                    drop constraint campaigns_segment_check,
                    add constraint campaigns_segment_check check (segment in ('all', 'going_quiet', 'top_10', 'quiet_14d'))
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table campaigns
                    drop constraint campaigns_segment_check,
                    add constraint campaigns_segment_check check (segment in ('all', 'quiet_14d', 'top_10')) not valid
                SQL);
        }
    }
};
