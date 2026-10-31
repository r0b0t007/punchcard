<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Your reward is ready" pushed to the customer's phones (CHW-41, 11d): when
 * it was, so a reward is pushed once, however often its job runs
 * (PushRewardsReady). Null: never pushed (a tap's own reward, none wanted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table): void {
            $table->timestamp('push_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $table): void {
            $table->dropColumn('push_sent_at');
        });
    }
};
