<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The businesses that honour a card (ADR 0006). Composite foreign keys keep
 * organization_id equal to the card's and the business's, so a card can
 * never be honoured by another organization's business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_business', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id');
            $table->foreignId('business_id');
            $table->timestamps();

            $table->foreign(['card_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('loyalty_cards')
                ->cascadeOnDelete();
            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->unique(['card_id', 'business_id']);
            $table->index(['organization_id', 'card_id']);
            $table->index(['business_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_business');
    }
};
