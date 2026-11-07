<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A referral stamp once per friend, held by the database too (CHW-47): its
 * key ("referral:{friend's enrollment}", GiveReferralStamp) is unique
 * whatever the business, where the stamp's own idempotency key is unique per
 * business only, and a franchise's card is honoured by several. A partial
 * index: no other stamp is touched.
 *
 * Built concurrently on Postgres, outside a transaction: every tap writes to
 * stamp_events, and a plain build would hold them until it ends.
 */
return new class extends Migration
{
    /** @var bool */
    public $withinTransaction = false;

    public function up(): void
    {
        $concurrently = DB::getDriverName() === 'pgsql' ? ' concurrently' : '';

        DB::statement("create unique index{$concurrently} if not exists stamp_events_referral_once on stamp_events (idempotency_key) where source = 'referral'");
    }

    public function down(): void
    {
        DB::statement('drop index if exists stamp_events_referral_once');
    }
};
