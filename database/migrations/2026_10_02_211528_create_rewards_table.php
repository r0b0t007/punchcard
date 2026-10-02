<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A reward a customer unlocked: one per milestone of an enrollment (the
 * completion number of a cyclic card, the tier threshold of a progressive
 * one), so the database enforces "a completed card creates exactly one
 * reward". It keeps a copy of what was earned, so changing the card later
 * does not change it. Redemption records the business and location
 * (ADR 0006): composite foreign keys keep them in the reward's organization
 * and the location in the business, and a business or location with
 * redemptions cannot be hard-deleted.
 *
 * On Postgres (production), CHECK constraints also refuse a location without
 * its business (a composite foreign key with a null column is not checked)
 * and a redeemed reward without where and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id');
            $table->unsignedInteger('milestone');
            $table->string('reward_type');
            $table->unsignedInteger('reward_value')->nullable();
            $table->string('reward_text');
            $table->string('status')->default('available');
            $table->timestamp('unlocked_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('redeemed_business_id')->nullable();
            $table->foreignId('redeemed_location_id')->nullable();
            $table->timestamps();

            $table->foreign(['enrollment_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('card_enrollments')
                ->cascadeOnDelete();
            $table->foreign(['redeemed_business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->noActionOnDelete();
            $table->foreign(['redeemed_location_id', 'redeemed_business_id'])
                ->references(['id', 'business_id'])->on('locations')
                ->noActionOnDelete();
            $table->unique(['enrollment_id', 'milestone']);
            $table->index(['organization_id', 'status']);
            $table->index(['redeemed_business_id', 'organization_id']);
            $table->index(['redeemed_location_id', 'redeemed_business_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table rewards
                    add constraint rewards_milestone_check check (milestone >= 1),
                    add constraint rewards_location_business_check check (
                        redeemed_location_id is null or redeemed_business_id is not null
                    ),
                    add constraint rewards_redeemed_check check (
                        status <> 'redeemed' or (redeemed_at is not null and redeemed_business_id is not null)
                    )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};
