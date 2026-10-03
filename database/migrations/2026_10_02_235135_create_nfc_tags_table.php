<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An NFC tag (NTAG 424 DNA): platform state, not a tenant's. Its keys are
 * derived from the uid and key_version, never stored (sun-nfc-verification
 * skill); last_counter is the replay guard the tap endpoint advances under a
 * row lock. A stamper assigns the tag to a business and location, and can be
 * removed or replaced; the tag itself stays, so a copied tap URL can never
 * become valid again.
 *
 * On every driver, triggers keep that true whatever writes the row: a tag is
 * never deleted (nor truncated), its uid never changes, its counter and key
 * version only move forward (re-provisioning bumps the version and keeps the
 * counter, per docs/runbooks/stamper-keys.md), and retiring it (lost,
 * stolen) is one-way.
 * On Postgres (production), CHECK constraints also refuse a uid that is not
 * 14 uppercase hex digits, key version 0 and a counter past 24 bits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_tags', function (Blueprint $table): void {
            $table->id();
            $table->char('uid', 14)->unique();
            $table->unsignedSmallInteger('key_version')->default(1);
            $table->unsignedInteger('last_counter')->default(0);
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table nfc_tags
                    add constraint nfc_tags_uid_check check (uid ~ '^[0-9A-F]{14}$'),
                    add constraint nfc_tags_key_version_check check (key_version >= 1),
                    add constraint nfc_tags_last_counter_check check (last_counter between 0 and 16777215)
                SQL);

            DB::unprepared(<<<'SQL_WRAP'
            create or replace function nfc_tags_forward_only() returns trigger language plpgsql as $$
            begin
                if new.uid is distinct from old.uid
                    or new.last_counter < old.last_counter
                    or new.key_version < old.key_version
                    or (old.retired_at is not null and new.retired_at is distinct from old.retired_at) then
                    raise exception 'nfc_tags_forward_only: a tag keeps its uid and retirement, and its counter and key version only move forward';
                end if;
            
                return new;
            end
            $$;
            
            create or replace function nfc_tags_never_deleted() returns trigger language plpgsql as $$
            begin
                raise exception 'nfc_tags_never_deleted: retire a tag instead';
            end
            $$;
            
            create trigger nfc_tags_forward_only before update on nfc_tags
                for each row execute function nfc_tags_forward_only();
            
            create trigger nfc_tags_never_deleted before delete on nfc_tags
                for each row execute function nfc_tags_never_deleted();
            
            -- TRUNCATE skips row triggers on Postgres; SQLite runs it as a DELETE.
            create trigger nfc_tags_never_truncated before truncate on nfc_tags
                for each statement execute function nfc_tags_never_deleted();
            SQL_WRAP);

            return;
        }

        DB::unprepared(<<<'SQL'
            create trigger nfc_tags_forward_only before update on nfc_tags
            when new.uid is not old.uid
                or new.last_counter < old.last_counter
                or new.key_version < old.key_version
                or (old.retired_at is not null and new.retired_at is not old.retired_at)
            begin
                select raise(abort, 'nfc_tags_forward_only: a tag keeps its uid and retirement, and its counter and key version only move forward');
            end;

            create trigger nfc_tags_never_deleted before delete on nfc_tags
            begin
                select raise(abort, 'nfc_tags_never_deleted: retire a tag instead');
            end;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_tags');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop function if exists nfc_tags_forward_only()');
            DB::statement('drop function if exists nfc_tags_never_deleted()');
        }
    }
};
