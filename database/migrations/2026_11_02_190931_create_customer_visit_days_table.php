<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The days each customer came in to each site (CHW-45, B3): one row per
 * customer, site and local day with a presence stamp there, written with the
 * customer's summary (SummariseCustomer) after each stamp. Site data, like
 * the summary: in a franchise, A1's rows are only visits to A1. The
 * dashboard counts distinct customers and days over them, on an index,
 * instead of reading every stamp of the last 60 days. Filled from the
 * ledger by punchcard:customers:summarise --once, after the deploy
 * (docs/runbooks/deploy.md), never here: a migration only changes the
 * schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_visit_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('location_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('visited_on');
            $table->timestamp('last_at');

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->foreign(['location_id', 'business_id'])
                ->references(['id', 'business_id'])->on('locations')
                ->cascadeOnDelete();
            $table->unique(['location_id', 'user_id', 'visited_on']);
            $table->index(['business_id', 'visited_on']);
            $table->index(['location_id', 'visited_on']);
            $table->index(['business_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_visit_days');
    }
};
