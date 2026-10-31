<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Rewards ready" in Settings (CHW-41, 11c): whether a customer's phones
 * with notifications on hear about a reward unlocked. The account's choice,
 * for every phone; a service notification, not marketing, so on by default
 * and no consent event. Offers stay on follows.notify.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('push_rewards')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('push_rewards');
        });
    }
};
