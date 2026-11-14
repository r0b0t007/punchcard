<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's card in a phone's Wallet (CHW-38, ADR 0003): one pass per
 * enrollment and platform. The serial names it in the pass and its pass code
 * (PassCode), random so it can't be guessed; the authentication token is the
 * pass web service's password, stored encrypted since every regenerated pass
 * carries it. changed_at says when its content last changed (the web service's
 * "updated since"), revoked_at when it stopped counting (removed from every
 * Wallet, or the account deleted). The composite key keeps organization_id the
 * enrollment's, as rewards do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_passes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id');
            $table->string('platform', 16);
            $table->string('serial', 32)->unique();
            $table->text('auth_token');
            $table->timestamp('changed_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign(['enrollment_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('card_enrollments')
                ->cascadeOnDelete();
            $table->unique(['enrollment_id', 'platform']);
            $table->index(['enrollment_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_passes');
    }
};
