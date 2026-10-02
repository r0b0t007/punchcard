<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A reward a customer unlocked: one per milestone of an enrollment (the
 * completion number of a cyclic card, the tier threshold of a progressive
 * one; mode tells them apart if the card's mode changes), so the database
 * enforces "a completed card creates exactly one reward". It keeps a copy
 * of what was earned, so changing the card later does not change it.
 * Redemption records the business and location (ADR 0006): composite
 * foreign keys keep them in the reward's organization and the location in
 * the business, and a business or location with redemptions cannot be
 * hard-deleted.
 *
 * On Postgres (production), CHECK constraints also refuse a redeemed reward
 * without when and where (so never a location without its business: a
 * composite foreign key with a null column is not checked), redemption
 * fields on a reward that was not redeemed, and a negative value.
 *
 * On every driver, a trigger makes the outcome final, whatever writes it
 * (a model, a bulk update, raw SQL, bypass()): a redeemed or expired reward
 * keeps its status, and a redeemed one its when and where. Redemption is
 * idempotent, and a franchise's "redeemed here" report cannot be rewritten.
 * redeemed_by stays free: deleting a staff member's account nulls it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id');
            $table->string('mode');
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
            $table->unique(['enrollment_id', 'mode', 'milestone']);
            $table->index(['organization_id', 'status']);
            $table->index(['redeemed_business_id', 'organization_id']);
            $table->index(['redeemed_location_id', 'redeemed_business_id']);
            $table->index('redeemed_by');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table rewards
                    add constraint rewards_milestone_check check (milestone >= 1),
                    add constraint rewards_redeemed_check check (
                        status <> 'redeemed' or (redeemed_at is not null and redeemed_business_id is not null)
                    ),
                    add constraint rewards_unredeemed_check check (
                        status = 'redeemed'
                        or (redeemed_at is null and redeemed_business_id is null and redeemed_location_id is null)
                    ),
                    add constraint rewards_value_check check (reward_value is null or reward_value >= 0)
                SQL);

            DB::unprepared(<<<'SQL'
                create function rewards_outcome_final() returns trigger language plpgsql as $$
                begin
                    if old.status <> 'available' and new.status is distinct from old.status then
                        raise exception 'rewards_outcome_final: a % reward stays %', old.status, old.status;
                    end if;

                    if old.status = 'redeemed' and (new.redeemed_at, new.redeemed_business_id, new.redeemed_location_id)
                        is distinct from (old.redeemed_at, old.redeemed_business_id, old.redeemed_location_id) then
                        raise exception 'rewards_outcome_final: a redeemed reward keeps when and where';
                    end if;

                    return new;
                end
                $$;

                create trigger rewards_outcome_final before update on rewards
                    for each row execute function rewards_outcome_final();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            create trigger rewards_outcome_final before update on rewards
            when old.status <> 'available' and (
                new.status is not old.status
                or (old.status = 'redeemed' and (
                    new.redeemed_at is not old.redeemed_at
                    or new.redeemed_business_id is not old.redeemed_business_id
                    or new.redeemed_location_id is not old.redeemed_location_id
                ))
            )
            begin
                select raise(abort, 'rewards_outcome_final: a redeemed or expired reward is final');
            end;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop function if exists rewards_outcome_final()');
        }
    }
};
