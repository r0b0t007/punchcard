<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A business's figures for one local day (CHW-45, B3), rolled up from the
 * ledger once the day has ended (RollUpDay): one row per open site, in its
 * time, and one for the whole business (no location), in the business's.
 * Site data: in a franchise, A1's rows are only A1's. Counts are integers.
 * Filled hourly by the schedule, and from history by
 * punchcard:stats:rollup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('location_id')->nullable();
            $table->date('day');
            // The day's time: a row from before it changed is counted live instead.
            $table->string('timezone');
            $table->unsignedInteger('visits');
            $table->unsignedInteger('new_customers');
            $table->unsignedInteger('stamps');
            $table->unsignedInteger('rewards_unlocked');
            $table->unsignedInteger('rewards_redeemed');
            $table->timestamps();

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->foreign(['location_id', 'business_id'])
                ->references(['id', 'business_id'])->on('locations')
                ->cascadeOnDelete();
            $table->unique(['location_id', 'day']);
            $table->index(['business_id', 'day']);
        });

        // One whole-business row a day: a unique index treats each missing location as different.
        DB::statement('create unique index business_daily_stats_business_day_unique on business_daily_stats (business_id, day) where location_id is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('business_daily_stats');
    }
};
