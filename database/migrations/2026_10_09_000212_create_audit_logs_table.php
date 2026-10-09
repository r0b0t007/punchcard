<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit log (CHW-34): what the platform admin did (verify, suspend or
 * reinstate a business; register, move, re-key or retire a tag), to what,
 * when and why. Platform data, written in the action's own transaction.
 *
 * Append-only on every driver: triggers refuse an update, a delete (and on
 * Postgres a truncate), whatever writes the row. actor_id has no foreign key,
 * and an admin with entries is anonymised rather than deleted (DeleteAccount),
 * so it keeps pointing at a row. actor_label is "admin #id" or "console",
 * never an email: nothing personal is frozen in a log that can't change.
 * context never holds key material.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label');
            $table->string('action', 64);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index('created_at');
            // DeleteAccount asks whether a user has entries.
            $table->index('actor_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                create or replace function audit_logs_append_only() returns trigger language plpgsql as $$
                begin
                    raise exception 'audit_logs_append_only: the audit log is never changed or deleted';
                end
                $$;

                create trigger audit_logs_no_update before update on audit_logs
                    for each row execute function audit_logs_append_only();

                create trigger audit_logs_no_delete before delete on audit_logs
                    for each row execute function audit_logs_append_only();

                create trigger audit_logs_no_truncate before truncate on audit_logs
                    for each statement execute function audit_logs_append_only();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            create trigger audit_logs_no_update before update on audit_logs
            begin
                select raise(abort, 'audit_logs_append_only: the audit log is never changed or deleted');
            end;

            create trigger audit_logs_no_delete before delete on audit_logs
            begin
                select raise(abort, 'audit_logs_append_only: the audit log is never changed or deleted');
            end;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop function if exists audit_logs_append_only()');
        }
    }
};
