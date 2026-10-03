<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A stamper: an NFC tag assigned to a location of a business (site data,
 * ADR 0006). The tag's uid, keys and replay counter live on nfc_tags, which
 * outlives any assignment: removing a stamper, or the business, never resets
 * them. A tag has at most one active stamper; moving it means disabling the
 * old assignment and adding a new one. Composite foreign keys keep a stamper
 * in its business's organization and at a location of that business; a
 * location with stampers cannot be deleted (move them first).
 *
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

        // One active assignment per tag; disabled ones stay for history.
        DB::statement("create unique index stampers_one_active_per_tag on stampers (nfc_tag_id) where status = 'active'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table stampers
                    add constraint stampers_status_check check (status in ('active', 'disabled')),
                    add constraint stampers_armed_check check (
                        (armed_qty is null and armed_until is null)
                        or (armed_qty between 1 and 10 and armed_until is not null)
                    )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stampers');
    }
};
