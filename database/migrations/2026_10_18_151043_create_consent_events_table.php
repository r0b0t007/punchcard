<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every change of a customer's consent (CHW-58): what (punchcard's news, or
 * a program's offers, with its organization), on or off, where from (a
 * sign-up form, a card's page or the settings), when. Append-only: the users
 * columns and follows rows hold the current state, this the history that
 * shows which consent covered a message sent on a given day. Triggers refuse
 * any UPDATE, on every driver. It is the person's data: it goes with the
 * account (cascade on delete, DeleteAccount when it anonymises).
 *
 * Consents given before the log are written into it from what is known:
 * news ticked at sign-up (the only way until now), and each follow, on since
 * it was created and off since its last change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->boolean('granted');
            $table->string('source');
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
        });

        $this->backfill();

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                create or replace function consent_events_append_only() returns trigger language plpgsql as $$
                begin
                    raise exception 'consent_events_append_only: a consent change is never rewritten; record a new one';
                end
                $$;

                create trigger consent_events_no_update before update on consent_events
                    for each row execute function consent_events_append_only();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            create trigger consent_events_no_update before update on consent_events
            begin
                select raise(abort, 'consent_events_append_only: a consent change is never rewritten; record a new one');
            end;
            SQL);
    }

    /** The consents given before the log, from what is known (public for its test). */
    public function backfill(): void
    {
        DB::table('users')->select(['id', 'marketing_opt_in_at'])->whereNotNull('marketing_opt_in_at')->orderBy('id')
            ->chunk(500, fn ($users) => DB::table('consent_events')->insert($users->map(fn (object $user): array => [
                'user_id' => $user->id, 'organization_id' => null, 'kind' => 'punchcard_news', 'granted' => true,
                'source' => 'sign_up', 'created_at' => $user->marketing_opt_in_at,
            ])->all()));

        DB::table('follows')->select(['id', 'user_id', 'organization_id', 'notify', 'created_at', 'updated_at'])->orderBy('id')
            ->chunk(500, fn ($follows) => DB::table('consent_events')->insert($follows->flatMap(fn (object $follow): array => [
                ['user_id' => $follow->user_id, 'organization_id' => $follow->organization_id, 'kind' => 'program_offers', 'granted' => true, 'source' => 'card', 'created_at' => $follow->created_at],
                ...((bool) $follow->notify ? [] : [['user_id' => $follow->user_id, 'organization_id' => $follow->organization_id, 'kind' => 'program_offers', 'granted' => false, 'source' => 'card', 'created_at' => $follow->updated_at]]),
            ])->all()));
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_events');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop function if exists consent_events_append_only()');
        }
    }
};
