<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An NFC stamper: an NTAG 424 DNA tag at one location of a business (site
 * data, ADR 0006). The keys are derived from the UID and key_version, never
 * stored (sun-nfc-verification skill); last_counter is the replay guard,
 * advanced by the tap endpoint under a row lock. Composite foreign keys keep
 * it in its business's organization and at a location of that business; a
 * location with stampers cannot be deleted (move them first).
 *
 * On every driver, a trigger keeps last_counter from ever going back, so a
 * copied tap URL can never become valid again, whatever writes the row.
 * On Postgres (production), CHECK constraints also refuse a uid that is not
 * 14 uppercase hex digits, key version 0, a counter past 24 bits, and arming
 * outside 1..10 stamps or without a deadline.
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
            $table->char('uid', 14)->unique();
            $table->string('label')->nullable();
            $table->unsignedSmallInteger('key_version')->default(1);
            $table->unsignedInteger('last_counter')->default(0);
            $table->string('status')->default('active');
            $table->unsignedTinyInteger('armed_qty')->nullable();
            $table->timestamp('armed_until')->nullable();
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
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table stampers
                    add constraint stampers_uid_check check (uid ~ '^[0-9A-F]{14}$'),
                    add constraint stampers_key_version_check check (key_version >= 1),
                    add constraint stampers_last_counter_check check (last_counter between 0 and 16777215),
                    add constraint stampers_armed_check check (
                        (armed_qty is null and armed_until is null)
                        or (armed_qty between 1 and 10 and armed_until is not null)
                    )
                SQL);

            DB::unprepared(<<<'SQL'
                create function stampers_counter_forward() returns trigger language plpgsql as $$
                begin
                    if new.last_counter < old.last_counter then
                        raise exception 'stampers_counter_forward: last_counter never goes back';
                    end if;

                    return new;
                end
                $$;

                create trigger stampers_counter_forward before update on stampers
                    for each row execute function stampers_counter_forward();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            create trigger stampers_counter_forward before update on stampers
            when new.last_counter < old.last_counter
            begin
                select raise(abort, 'stampers_counter_forward: last_counter never goes back');
            end;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stampers');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop function if exists stampers_counter_forward()');
        }
    }
};
