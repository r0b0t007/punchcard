<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a campaign's delivery was queued (CHW-42): at once for one sent now,
 * by the scheduler for one scheduled (DispatchDueCampaigns, which claims it
 * here once), so a backlog never queues it again every minute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->timestamp('queued_at')->nullable()->after('send_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('queued_at');
        });
    }
};
