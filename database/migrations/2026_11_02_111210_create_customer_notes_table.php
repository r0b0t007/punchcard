<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes on a customer (CHW-44, B5), for the business's team: site data, like
 * the customers list, so a franchisee's notes are its own. Keyed to the
 * person, not their summary row, which comes and goes with the ledger. They
 * go with the customer's account; a deleted author leaves the note unsigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at');

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->index(['business_id', 'user_id', 'id']);
            $table->index('user_id');
            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
    }
};
