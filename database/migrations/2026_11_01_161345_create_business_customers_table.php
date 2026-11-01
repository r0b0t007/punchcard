<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A business's customers (CHW-44, B4): one row per customer who came in, with
 * a presence stamp at this business, summarised from the ledger
 * (SummariseCustomer) after each stamp and redemption. Site data, like
 * campaigns: in a franchise, A1's rows are only who visited A1. When they go
 * quiet and lost is stored, so each segment of the list is a range on an
 * index, at 50k customers too, and a status moves with time on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('visits');
            $table->timestamp('first_visit_at');
            $table->timestamp('last_visit_at');
            $table->unsignedSmallInteger('usual_gap_days')->nullable();
            $table->timestamp('quiet_at');
            $table->timestamp('lost_at');
            $table->unsignedInteger('rewards_redeemed')->default(0);
            $table->timestamps();

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->unique(['business_id', 'user_id']);
            $table->index(['business_id', 'last_visit_at']);
            $table->index(['business_id', 'quiet_at']);
            $table->index(['business_id', 'lost_at']);
            $table->index(['business_id', 'first_visit_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_customers');
    }
};
