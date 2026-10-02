<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff membership: owner or staff of one business, staff optionally limited
 * to one location. Composite foreign keys keep organization_id equal to the
 * business's and location_id inside the same business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable();
            $table->string('role')->default('staff');
            $table->timestamps();

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            // A location with staff assigned cannot be deleted until they are reassigned.
            // NO ACTION (checked at the end of the statement) rather than RESTRICT, so a
            // business or organization delete cascading through both paths never depends
            // on the order the database fires them.
            $table->foreign(['location_id', 'business_id'])
                ->references(['id', 'business_id'])->on('locations')
                ->noActionOnDelete();
            $table->unique(['business_id', 'user_id']);
            $table->index(['business_id', 'organization_id']);
            $table->index('user_id');
            $table->index(['location_id', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_user');
    }
};
