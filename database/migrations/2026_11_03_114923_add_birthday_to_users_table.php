<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's birthday, optional (CHW-46, C12): the day and the month, never
 * the year, for a birthday treat from the places they collect stamps at.
 * Both or neither, and a day that month has in a leap year (29 February is
 * one). Postgres only, like the other CHECKs; ProfileUpdateRequest says the
 * same on every driver. "Both or neither" is its own clause: with one of the
 * two null, the date clause is null, and a CHECK lets a null through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedTinyInteger('birthday_month')->nullable();
            $table->unsignedTinyInteger('birthday_day')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table users add constraint users_birthday_check check (
                    (birthday_month is null) = (birthday_day is null)
                    and (
                        birthday_month is null
                        or (
                            birthday_month between 1 and 12
                            and birthday_day between 1 and case
                                when birthday_month = 2 then 29
                                when birthday_month in (4, 6, 9, 11) then 30
                                else 31
                            end
                        )
                    )
                )
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table users drop constraint users_birthday_check');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['birthday_month', 'birthday_day']);
        });
    }
};
