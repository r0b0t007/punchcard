<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A business's average ticket (CHW-48, B10): what a visit is worth, in
 * centimes like every amount (loyalty_cards.reward_value), set by its owner
 * on a campaign's results to estimate the revenue its extra visits brought.
 * Unset until then. 1 to 10,000 dirhams (a Postgres CHECK; the form says
 * the same on every driver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->unsignedInteger('average_ticket')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table businesses add constraint businesses_average_ticket_check check (average_ticket between 100 and 1000000)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table businesses drop constraint businesses_average_ticket_check');
        }

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn('average_ticket');
        });
    }
};
