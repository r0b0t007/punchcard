<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a customer turned punchcard's news off (CHW-58): with
 * marketing_opt_in_at, each state of the consent keeps its date (on since,
 * off since), and an opt-out is never undone by a later sign-up form
 * (RecordConsent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('marketing_opt_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('marketing_opt_out_at');
        });
    }
};
