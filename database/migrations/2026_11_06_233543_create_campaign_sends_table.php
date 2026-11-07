<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who a campaign went to (CHW-48, B10): site data, one row per follower,
 * claimed before the push so a batch run again never pushes twice; sent_at
 * once a phone's push service accepted it, opened_at at the first tap on it.
 * The campaign's results (its opens, and the visits its recipients made in
 * the 48 h after it) read it. Its campaign is its business's own (the
 * campaigns' (id, business_id) key). sent_at and opened_at are each filled
 * in once and nothing else ever changes (a trigger, on both drivers). Gone
 * with the customer's account (DeleteAccount: the campaign's counts stay) or
 * the business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->unique(['id', 'business_id']);
        });

        Schema::create('campaign_sends', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('campaign_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('created_at');

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->foreign(['campaign_id', 'business_id'])
                ->references(['id', 'business_id'])->on('campaigns')
                ->cascadeOnDelete();
            $table->unique(['campaign_id', 'user_id']);
            $table->index('user_id');
            // The org-admin scope filters on organization_id.
            $table->index(['organization_id', 'business_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                create or replace function campaign_sends_filled_once() returns trigger language plpgsql as $$
                begin
                    if (old.sent_at is not null and new.sent_at is distinct from old.sent_at)
                        or (old.opened_at is not null and new.opened_at is distinct from old.opened_at)
                        or (new.id, new.organization_id, new.business_id, new.campaign_id, new.user_id, new.created_at)
                            is distinct from (old.id, old.organization_id, old.business_id, old.campaign_id, old.user_id, old.created_at) then
                        raise exception 'campaign_sends_filled_once: a send is kept as claimed; only its sent_at and opened_at are filled in, once each';
                    end if;

                    return new;
                end
                $$;

                create trigger campaign_sends_filled_once before update on campaign_sends
                    for each row execute function campaign_sends_filled_once();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            create trigger campaign_sends_filled_once before update on campaign_sends
            when (old.sent_at is not null and new.sent_at is not old.sent_at)
                or (old.opened_at is not null and new.opened_at is not old.opened_at)
                or new.id is not old.id or new.organization_id is not old.organization_id or new.business_id is not old.business_id
                or new.campaign_id is not old.campaign_id or new.user_id is not old.user_id or new.created_at is not old.created_at
            begin
                select raise(abort, 'campaign_sends_filled_once: a send is kept as claimed; only its sent_at and opened_at are filled in, once each');
            end;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_sends');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop function if exists campaign_sends_filled_once()');
        }

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropUnique(['id', 'business_id']);
        });
    }
};
