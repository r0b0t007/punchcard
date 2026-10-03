<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The stamp ledger: append-only, the source of truth for every count
 * (enrollment counters are a cache of it). Each event records where it
 * happened, the business and location (ADR 0006 attribution), and how: a
 * verified tap (the tag and its counter), a staff scan (an idempotency
 * key), or a manual stamp or correction (a reason).
 *
 * Nothing cascades into it and nothing it points at can be hard-deleted:
 * every foreign key is NO ACTION, and erasure is anonymisation (CHW-139).
 * Composite foreign keys keep an event in its enrollment's organization, at
 * a location of its business, on a stamper of that business, and with that
 * stamper's tag. unique(nfc_tag_id, counter) is the second replay guard,
 * keyed on the tag so a reassigned tag cannot slip past it. The request's IP
 * and user agent are personal data and belong to the tap log, which can be
 * purged; this table never changes, so it holds none.
 *
 * On every driver, triggers refuse any UPDATE or DELETE (and TRUNCATE on
 * Postgres), whatever writes it. On Postgres (production), CHECK constraints
 * also keep qty between -50 and 50 and not 0 (1..10 on a tap, the arming
 * cap), negative only for a correction, a non-empty reason on manual stamps
 * and corrections, a staff member and an idempotency key on staff stamps, a
 * tag, stamper and counter on taps and only on taps, and a known source.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Target of the composite foreign key that keeps an event's tag the stamper's.
        Schema::table('stampers', function (Blueprint $table): void {
            $table->unique(['id', 'nfc_tag_id']);
        });

        Schema::create('stamp_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->noActionOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('location_id');
            $table->foreignId('enrollment_id');
            $table->foreignId('stamper_id')->nullable();
            $table->foreignId('nfc_tag_id')->nullable()->constrained('nfc_tags')->noActionOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->noActionOnDelete();
            $table->string('source');
            $table->smallInteger('qty');
            $table->unsignedInteger('counter')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['enrollment_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('card_enrollments')
                ->noActionOnDelete();
            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->noActionOnDelete();
            $table->foreign(['location_id', 'business_id'])
                ->references(['id', 'business_id'])->on('locations')
                ->noActionOnDelete();
            $table->foreign(['stamper_id', 'business_id'])
                ->references(['id', 'business_id'])->on('stampers')
                ->noActionOnDelete();
            $table->foreign(['stamper_id', 'nfc_tag_id'])
                ->references(['id', 'nfc_tag_id'])->on('stampers')
                ->noActionOnDelete();
            $table->unique(['nfc_tag_id', 'counter']);
            // A staff scan or manual stamp is applied once per business; scoped, so another
            // organization's key can neither block nor reveal one here.
            $table->unique(['business_id', 'idempotency_key']);
            // The "stamped here" rule (EXISTS an event for this enrollment at this business)
            // and the daily cap (this customer's stamps here today).
            $table->index(['enrollment_id', 'business_id', 'created_at']);
            $table->index(['business_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['location_id', 'business_id']);
            $table->index(['stamper_id', 'business_id']);
            $table->index('staff_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table stamp_events
                    add constraint stamp_events_qty_check check (qty <> 0 and qty between -50 and 50),
                    add constraint stamp_events_negative_check check (qty > 0 or source = 'correction'),
                    add constraint stamp_events_tap_qty_check check (source <> 'nfc' or qty between 1 and 10),
                    add constraint stamp_events_reason_check check (
                        source not in ('manual', 'correction') or (reason is not null and reason <> '')
                    ),
                    add constraint stamp_events_staff_check check (
                        source not in ('qr', 'manual', 'correction') or (staff_id is not null and idempotency_key is not null)
                    ),
                    add constraint stamp_events_nfc_check check (
                        (source = 'nfc') = (nfc_tag_id is not null and stamper_id is not null and counter is not null)
                        and (source = 'nfc' or (nfc_tag_id is null and stamper_id is null and counter is null))
                    ),
                    add constraint stamp_events_source_check check (
                        source in ('nfc', 'qr', 'manual', 'bonus', 'birthday', 'referral', 'correction')
                    )
                SQL);

            DB::unprepared(<<<'SQL'
                create or replace function stamp_events_append_only() returns trigger language plpgsql as $$
                begin
                    raise exception 'stamp_events_append_only: the ledger is never changed; add a correction';
                end
                $$;

                create trigger stamp_events_no_update before update on stamp_events
                    for each row execute function stamp_events_append_only();

                create trigger stamp_events_no_delete before delete on stamp_events
                    for each row execute function stamp_events_append_only();

                create trigger stamp_events_no_truncate before truncate on stamp_events
                    for each statement execute function stamp_events_append_only();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            create trigger stamp_events_no_update before update on stamp_events
            begin
                select raise(abort, 'stamp_events_append_only: the ledger is never changed; add a correction');
            end;

            create trigger stamp_events_no_delete before delete on stamp_events
            begin
                select raise(abort, 'stamp_events_append_only: the ledger is never changed; add a correction');
            end;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_events');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop function if exists stamp_events_append_only()');
        }

        Schema::table('stampers', function (Blueprint $table): void {
            $table->dropUnique(['id', 'nfc_tag_id']);
        });
    }
};
