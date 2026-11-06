<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a customer gave their birthday (CHW-370): a birthday counts for the
 * bonus 30 days after it is added or changed, so setting today as one gains
 * nothing. Birthdays already given wait from the account's last change
 * (updated_at), never earlier than they were really given: any later save
 * only moves it later. Both or neither, a Postgres CHECK like
 * users_birthday_check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('birthday_set_at')->nullable();
        });

        DB::table('users')->whereNotNull('birthday_month')->whereNotNull('updated_at')->update(['birthday_set_at' => DB::raw('updated_at')]);
        // Now in UTC, as the app stores times: the database session's timezone is not pinned.
        DB::table('users')->whereNotNull('birthday_month')->whereNull('updated_at')->update(['birthday_set_at' => now()->utc()]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table users add constraint users_birthday_set_at_check check ((birthday_month is null) = (birthday_set_at is null))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table users drop constraint users_birthday_set_at_check');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('birthday_set_at');
        });
    }
};
