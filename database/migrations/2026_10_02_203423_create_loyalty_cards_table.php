<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A loyalty card: program data owned by the organization, so a franchise's
 * franchisees share it (ADR 0006). card_business lists the businesses that
 * honour it. Money and counts are integers: reward_value is a percent or an
 * amount in centimes. Anti-fraud defaults from docs/spec.md: a 20 minute
 * cooldown and a daily cap of 5 stamps.
 *
 * On Postgres (production), CHECK constraints also refuse values the stamp
 * flow cannot work with, such as 0 stamps required, which would unlock a
 * reward on every stamp. The card editor's Form Request validates them first;
 * SQLite cannot add these constraints to the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('stamps_required');
            $table->string('mode')->default('cyclic');
            $table->json('tiers')->nullable();
            $table->string('stamp_style')->nullable();
            $table->string('banner_path')->nullable();
            $table->string('reward_type')->default('item');
            $table->unsignedInteger('reward_value')->nullable();
            $table->string('reward_text');
            $table->json('colors')->nullable();
            $table->string('icon')->nullable();
            $table->text('terms')->nullable();
            $table->unsignedInteger('cooldown_min')->default(20);
            $table->unsignedSmallInteger('daily_cap')->nullable()->default(5);
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Target of composite foreign keys that keep program data in the card's organization.
            $table->unique(['id', 'organization_id']);
            $table->index('organization_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table loyalty_cards
                    add constraint loyalty_cards_stamps_required_check check (stamps_required between 5 and 50),
                    add constraint loyalty_cards_cooldown_min_check check (cooldown_min >= 0),
                    add constraint loyalty_cards_daily_cap_check check (daily_cap is null or daily_cap >= 1),
                    add constraint loyalty_cards_reward_value_check check (
                        reward_type = 'item' or (reward_value is not null and reward_value > 0)
                    ),
                    add constraint loyalty_cards_percent_check check (reward_type <> 'percent' or reward_value <= 100)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_cards');
    }
};
