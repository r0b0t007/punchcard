<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A location's latest stamps (RecentStamps, CHW-29): counter mode polls them
 * every few seconds for staff limited to one location, so the read walks
 * that location's newest rows rather than sorting all of them. An index only:
 * the table's triggers stay as they are on every driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stamp_events', function (Blueprint $table): void {
            $table->index(['location_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stamp_events', function (Blueprint $table): void {
            $table->dropIndex(['location_id', 'created_at']);
        });
    }
};
