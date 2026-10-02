<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The site-level tenant: a legal entity (a franchisee, or an independent café)
 * that runs locations, staff and stampers. Ownership lives in business_user
 * (role owner), not in a column here, so there is one source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category')->nullable();
            $table->string('status')->default('pending');
            $table->string('plan')->nullable();
            $table->timestamps();

            // Target of composite foreign keys that keep site data's organization_id
            // equal to its business's organization.
            $table->unique(['id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
