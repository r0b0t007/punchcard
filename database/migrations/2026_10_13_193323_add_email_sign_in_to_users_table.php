<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers who sign up with an email link (CHW-23) have no password, and
 * every new account says it is 16 or older and whether it wants news by
 * email, each with when (CNDP): null is "not given", never assumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
            $table->timestamp('adult_confirmed_at')->nullable();
            $table->timestamp('marketing_opt_in_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['adult_confirmed_at', 'marketing_opt_in_at']);
        });

        // Accounts without a password get one that matches nothing (Hash::check refuses an empty hash).
        DB::table('users')->whereNull('password')->update(['password' => '']);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->change();
        });
    }
};
