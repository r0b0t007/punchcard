<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The brand and card program owner (ADR 0006). Every business belongs to one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('independent');
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->string('cover_path')->nullable();
            $table->string('billing_entity')->default('organization');
            $table->string('plan')->nullable();
            $table->boolean('white_label')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
