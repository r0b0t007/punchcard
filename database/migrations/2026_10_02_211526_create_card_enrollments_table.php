<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's copy of a card: progress shared across every business that
 * honours it (ADR 0006). The counts are a cache of stamp_events (CHW-21 PR C).
 * The composite foreign key keeps organization_id equal to the card's; a card
 * with members cannot be deleted (deactivate it instead), while deleting the
 * organization still removes everything. A referrer is on the same card, and
 * on Postgres never the enrollment itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('referral_code')->nullable()->unique();
            // The referral survives as "referred" when the referrer's enrollment is erased.
            $table->foreignId('referred_by')->nullable()->constrained('card_enrollments')->nullOnDelete();
            $table->unsignedInteger('current_stamps')->default(0);
            $table->unsignedInteger('lifetime_stamps')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->timestamp('last_stamp_at')->nullable();
            $table->timestamps();

            // NO ACTION (checked at the end of the statement), so an organization delete
            // cascading through both the card and the enrollment never depends on order.
            $table->foreign(['card_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('loyalty_cards')
                ->noActionOnDelete();
            $table->unique(['card_id', 'user_id']);
            // Targets of the composite foreign keys that keep a reward in its enrollment's
            // organization and a referrer on the same card.
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'card_id']);
            // NO ACTION: when the referrer is erased, the key above sets referred_by to
            // null first, and a null column skips this check.
            $table->foreign(['referred_by', 'card_id'])
                ->references(['id', 'card_id'])->on('card_enrollments')
                ->noActionOnDelete();
            $table->index(['organization_id', 'card_id']);
            $table->index('user_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table card_enrollments add constraint card_enrollments_referral_check check (referred_by <> id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('card_enrollments');
    }
};
