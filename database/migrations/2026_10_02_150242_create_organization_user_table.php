<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization-level roles (org_admin: franchise HQ, or the owner of an
 * independent café). Business roles live in business_user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('org_admin');
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
    }
};
