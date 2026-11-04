<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every automation message (CHW-46): site data, claimed before it is sent,
 * so the occasion (a quiet spell, a birthday's year, a reward) is sent once
 * whatever runs again; sent_at once a phone's push service accepted it. The
 * weekly cap reads a customer's latest at the business. Its automation is
 * its business's own, for the same trigger, and is kept while it has sends
 * (no action: a business's deletion still takes both at once). sent_at is
 * filled in once and nothing else ever changes (a trigger, on both drivers).
 * Gone with the customer's account (deleted, or anonymised: DeleteAccount)
 * or the business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_sends', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('automation_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 20);
            $table->string('occasion', 64);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at');

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->foreign(['automation_id', 'business_id', 'trigger'])
                ->references(['id', 'business_id', 'trigger'])->on('automations')
                ->noActionOnDelete();
            $table->unique(['automation_id', 'user_id', 'occasion']);
            $table->index(['business_id', 'user_id', 'created_at']);
            $table->index('user_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                alter table automation_sends
                    add constraint automation_sends_trigger_check check (trigger in ('win_back', 'birthday', 'reward_reminder'));

                create or replace function automation_sends_sent_once() returns trigger language plpgsql as $$
                begin
                    if old.sent_at is not null
                        or (new.id, new.organization_id, new.business_id, new.automation_id, new.user_id, new.trigger, new.occasion, new.created_at)
                            is distinct from (old.id, old.organization_id, old.business_id, old.automation_id, old.user_id, old.trigger, old.occasion, old.created_at) then
                        raise exception 'automation_sends_sent_once: a send is kept as claimed; only its sent_at is filled in, once';
                    end if;

                    return new;
                end
                $$;

                create trigger automation_sends_sent_once before update on automation_sends
                    for each row execute function automation_sends_sent_once();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            create trigger automation_sends_sent_once before update on automation_sends
            when old.sent_at is not null
                or new.id is not old.id or new.organization_id is not old.organization_id or new.business_id is not old.business_id
                or new.automation_id is not old.automation_id or new.user_id is not old.user_id or new.trigger is not old.trigger
                or new.occasion is not old.occasion or new.created_at is not old.created_at
            begin
                select raise(abort, 'automation_sends_sent_once: a send is kept as claimed; only its sent_at is filled in, once');
            end;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_sends');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop function if exists automation_sends_sent_once()');
        }
    }
};
