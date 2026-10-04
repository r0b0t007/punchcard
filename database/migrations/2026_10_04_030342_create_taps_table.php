<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tap log (CHW-25): one row per tap on /t, received or refused. Platform
 * data (IsPlatformData), written by the tap Actions in bypass().
 *
 * - A tap whose MAC verified carries its tag and counter; unique
 *   (nfc_tag_id, counter) is a second replay guard after the tag's
 *   last_counter, and it covers refused counters too. A malformed, forged or
 *   unknown-tag tap carries neither: nothing in it can be trusted, and an
 *   untrusted counter must never block a real one.
 * - A pending tap waits for its customer (signed out at the counter) until
 *   expires_at; ApplyTap stamps it once, where it happened (location_id),
 *   and records the outcome.
 * - A refused tap whose tag still has a stamper names it and its business
 *   (a replayed or copied URL included), for the owner's fraud view.
 * - ip and user_agent are personal data: rows are pruned after
 *   punchcard.taps.retention_days, and DeleteAccount scrubs a user's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nfc_tag_id')->nullable()->constrained()->noActionOnDelete();
            $table->foreignId('stamper_id')->nullable()->constrained()->noActionOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->noActionOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->noActionOnDelete();
            $table->unsignedInteger('counter')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('qty')->default(1);
            $table->string('status');
            $table->string('rejection')->nullable();
            $table->foreignId('stamp_event_id')->nullable()->constrained()->noActionOnDelete();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->unique(['nfc_tag_id', 'counter']);
            $table->index(['business_id', 'created_at']);
            $table->index('created_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table taps
                    add constraint taps_status_check check (status in ('pending', 'stamped', 'rejected', 'expired')),
                    add constraint taps_qty_check check (qty between 1 and 10),
                    add constraint taps_counter_check check (counter is null or nfc_tag_id is not null),
                    add constraint taps_rejection_check check ((status in ('rejected', 'expired')) = (rejection is not null)),
                    add constraint taps_pending_check check (
                        status <> 'pending' or (stamper_id is not null and business_id is not null and location_id is not null and counter is not null and expires_at is not null)
                    )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('taps');
    }
};
