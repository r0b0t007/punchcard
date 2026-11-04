<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A business's automations (CHW-46, B11): site data, one row per trigger the
 * owner set up, on or off. days is the reward reminder's wait, stamps the
 * birthday's bonus; title and body are the owner's own message, or null for
 * ours (lang). Never deleted, nor its trigger changed: its sends point at
 * it, with its business and trigger (automation_sends). Gone with the
 * business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->string('trigger', 20);
            $table->boolean('active')->default(false);
            $table->unsignedSmallInteger('days')->default(7);
            $table->unsignedTinyInteger('stamps')->default(1);
            $table->string('title', 64)->nullable();
            $table->string('body', 255)->nullable();
            $table->timestamps();

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->unique(['business_id', 'trigger']);
            // What a send points at: this automation, of its business, for its trigger.
            $table->unique(['id', 'business_id', 'trigger']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table automations
                    add constraint automations_trigger_check check (trigger in ('win_back', 'birthday', 'reward_reminder')),
                    add constraint automations_days_check check (days between 1 and 60),
                    add constraint automations_stamps_check check (stamps between 1 and 3)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('automations');
    }
};
