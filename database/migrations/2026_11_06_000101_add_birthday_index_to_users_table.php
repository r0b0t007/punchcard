<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Whose birthday a day is (CHW-46, User::birthdayOn): read by the hourly
 * automations for every business with the birthday bonus on, so it needs no
 * scan of every account. Only accounts that gave their birthday.
 *
 * Built concurrently on Postgres, outside a transaction: sign-ins and taps
 * keep writing to users while it builds.
 */
return new class extends Migration
{
    /** @var bool */
    public $withinTransaction = false;

    public function up(): void
    {
        $concurrently = DB::getDriverName() === 'pgsql' ? ' concurrently' : '';

        DB::statement("create index{$concurrently} if not exists users_birthday_index on users (birthday_month, birthday_day) where birthday_month is not null");
    }

    public function down(): void
    {
        DB::statement('drop index if exists users_birthday_index');
    }
};
