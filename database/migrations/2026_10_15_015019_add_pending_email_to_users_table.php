<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A new address asked for in settings, until it is confirmed from its own
 * inbox (CHW-23, ApplyEmailChange): the account keeps its address, and all
 * that goes with it (sign-in links, confirming, resets), until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pending_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('pending_email');
        });
    }
};
