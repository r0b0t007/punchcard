<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A franchise's customers across its sites (CHW-365, B4 at network level): one row per customer of any of the
 * organization's businesses, summarised with their row there (SummariseCustomer). A day at two sites is one visit;
 * they go quiet and are lost only when they stop coming to every site. Customer data of the organization, read by its
 * org admin alone (never a franchisee). When they go quiet and lost is stored, so each tab is a range on an index, at
 * 50k customers too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('visits');
            $table->timestamp('first_visit_at');
            $table->timestamp('last_visit_at');
            $table->unsignedSmallInteger('usual_gap_days')->nullable();
            $table->timestamp('quiet_at');
            $table->timestamp('lost_at');
            $table->unsignedInteger('rewards_redeemed')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['organization_id', 'last_visit_at']);
            $table->index(['organization_id', 'quiet_at']);
            $table->index(['organization_id', 'lost_at']);
            $table->index(['organization_id', 'first_visit_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_customers');
    }
};
