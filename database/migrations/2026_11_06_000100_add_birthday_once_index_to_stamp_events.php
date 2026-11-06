<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A birthday bonus once a year per card, held by the database too (CHW-46):
 * its key ("birthday:{card}:{year}", App\Support\Automations\BirthdayKey) is
 * unique per card whatever the business, where the stamp's own idempotency
 * key is unique per business only. A partial index: Postgres and SQLite both
 * take the same statement, and no other stamp is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("create unique index stamp_events_birthday_once on stamp_events (enrollment_id, idempotency_key) where source = 'birthday'");
    }

    public function down(): void
    {
        DB::statement('drop index if exists stamp_events_birthday_once');
    }
};
