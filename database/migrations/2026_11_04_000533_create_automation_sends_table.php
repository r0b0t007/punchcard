<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every automation message (CHW-46): site data, claimed before it is sent,
 * so the occasion (a quiet spell, a birthday's year, a reward) is sent once
 * whatever runs again; sent_at once a phone's push service accepted it. The
 * weekly cap reads a customer's latest at the business. Gone with the
 * customer's account or the business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_sends', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 20);
            $table->string('occasion', 64);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at');

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->unique(['automation_id', 'user_id', 'occasion']);
            $table->index(['business_id', 'user_id', 'created_at']);
            $table->index('user_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table automation_sends
                    add constraint automation_sends_trigger_check check (trigger in ('win_back', 'birthday', 'reward_reminder'))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_sends');
    }
};
