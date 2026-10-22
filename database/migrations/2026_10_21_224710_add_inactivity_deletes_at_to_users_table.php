<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day an inactivity warning promised (CHW-58): the sweep never deletes
 * an account before it, whatever the limits say by then
 * (AnonymiseInactiveAccounts). Set with inactivity_warned_at, cleared with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('inactivity_deletes_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('inactivity_deletes_at');
        });
    }
};
