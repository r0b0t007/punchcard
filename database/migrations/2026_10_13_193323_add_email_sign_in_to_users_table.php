<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers who sign up with an email link (CHW-23) have no password, and
 * every new account says it is 16 or older and whether it wants news by
 * email, each with when (CNDP): null is "not given", never assumed.
 * Stored addresses are lowercased, as they are looked up from now on.
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

        // Addresses are looked up in lower case from now on (User::normalizeEmail): the ones stored already too.
        // Two accounts differing only by case make this fail, to be settled by hand rather than merged.
        if (config('fortify.lowercase_usernames')) {
            DB::table('users')->update(['email' => DB::raw('lower(email)')]);
        }
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
