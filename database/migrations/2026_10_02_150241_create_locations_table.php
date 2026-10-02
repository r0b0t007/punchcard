<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A physical site. Site data carries organization_id as well as business_id,
 * so organization-level reports never join across tenants (ADR 0006). The
 * composite foreign key makes the database refuse a location whose
 * organization_id differs from its business's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->string('name');
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('timezone')->default('Africa/Casablanca');
            $table->timestamps();

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'business_id']);
            // Target for staff and stamp rows that must stay within one business.
            $table->unique(['id', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
