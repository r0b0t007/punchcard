<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A stamper: an NFC tag assigned to a location of a business (site data,
 * ADR 0006). The tag's uid, keys and replay counter live on nfc_tags, which
 * outlives any assignment: removing a stamper, or the business, never resets
 * them. A tag has at most one current stamper: ending an assignment
 * (unassigned_at, set by an admin, one-way by trigger) frees the tag for a
 * new one, while a business pausing its stamper (status) keeps its claim, so
 * an old holder can never take a moved tag back. Composite foreign keys keep
 * a stamper in its business's organization and at a location of that
 * business; a location with stampers cannot be deleted (move them first).
 *
 * On every driver, triggers also keep an assignment's tag fixed and refuse
 * assigning a retired (lost, stolen) tag, whatever writes the row.
 * On Postgres (production), CHECK constraints also refuse an unknown status
 * (so the tap endpoint never fails on the enum cast) and arming outside 1..10
 * stamps or without a deadline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stampers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('location_id');
            $table->foreignId('nfc_tag_id')->constrained('nfc_tags');
            $table->string('label')->nullable();
            $table->string('status')->default('active');
            $table->unsignedTinyInteger('armed_qty')->nullable();
            $table->timestamp('armed_until')->nullable();
            $table->timestamp('unassigned_at')->nullable();
            $table->timestamps();

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            // NO ACTION (checked at the end of the statement), so a business delete
            // cascading through both the stamper and the location never depends on order.
            $table->foreign(['location_id', 'business_id'])
                ->references(['id', 'business_id'])->on('locations')
                ->noActionOnDelete();
            // Target of the composite foreign key that keeps a stamp event at the stamper's business.
            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'organization_id']);
            $table->index(['location_id', 'business_id']);
            $table->index('nfc_tag_id');
        });

        // One current assignment per tag, paused or not; ended ones stay for history.
        DB::statement('create unique index stampers_one_current_per_tag on stampers (nfc_tag_id) where unassigned_at is null');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table stampers
                    add constraint stampers_status_check check (status in ('active', 'disabled')),
                    add constraint stampers_armed_check check (
                        (armed_qty is null and armed_until is null)
                        or (armed_qty between 1 and 10 and armed_until is not null)
                    )
                SQL);

            DB::unprepared(<<<'SQL'
                create or replace function stampers_assignment_ended() returns trigger language plpgsql as $$
                begin
                    if new.nfc_tag_id is distinct from old.nfc_tag_id then
                        raise exception 'stampers_tag_fixed: an assignment keeps its tag';
                    end if;

                    if old.unassigned_at is not null and new.unassigned_at is distinct from old.unassigned_at then
                        raise exception 'stampers_assignment_ended: an ended assignment stays ended';
                    end if;

                    return new;
                end
                $$;

                create trigger stampers_assignment_ended before update on stampers
                    for each row execute function stampers_assignment_ended();

                create or replace function stampers_tag_not_retired() returns trigger language plpgsql as $$
                begin
                    if exists (select 1 from nfc_tags where id = new.nfc_tag_id and retired_at is not null) then
                        raise exception 'stampers_tag_retired: a retired tag cannot be assigned';
                    end if;

                    return new;
                end
                $$;

                create trigger stampers_tag_not_retired before insert on stampers
                    for each row execute function stampers_tag_not_retired();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            create trigger stampers_tag_fixed before update on stampers
            when new.nfc_tag_id is not old.nfc_tag_id
            begin
                select raise(abort, 'stampers_tag_fixed: an assignment keeps its tag');
            end;

            create trigger stampers_assignment_ended before update on stampers
            when old.unassigned_at is not null and new.unassigned_at is not old.unassigned_at
            begin
                select raise(abort, 'stampers_assignment_ended: an ended assignment stays ended');
            end;

            create trigger stampers_tag_not_retired before insert on stampers
            when (select retired_at from nfc_tags where id = new.nfc_tag_id) is not null
            begin
                select raise(abort, 'stampers_tag_retired: a retired tag cannot be assigned');
            end;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stampers');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop function if exists stampers_assignment_ended()');
            DB::statement('drop function if exists stampers_tag_not_retired()');
        }
    }
};
