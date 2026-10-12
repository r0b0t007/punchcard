<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follows (CHW-27, docs/spec.md): a customer's consent to hear about an
 * organization's offers, given on a card's page (C6). No row means not
 * following; turning it off keeps the row with notify false, so the
 * consent's last change stays dated. Deleting the user deletes theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Consent is given, never assumed: an insert that leaves it out records none.
            $table->boolean('notify')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'organization_id']);
            $table->index(['organization_id', 'notify']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
