<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A loyalty card: program data owned by the organization, so a franchise's
 * franchisees share it (ADR 0006). card_business lists the businesses that
 * honour it. Money and counts are integers: reward_value is a percent or an
 * amount in centimes.
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
            $table->unsignedInteger('cooldown_min')->default(0);
            $table->unsignedSmallInteger('daily_cap')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Target of composite foreign keys that keep program data in the card's organization.
            $table->unique(['id', 'organization_id']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_cards');
    }
};
