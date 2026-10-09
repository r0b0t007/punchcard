<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the platform admin verified a business, and when it was suspended
 * (CHW-34): set only by VerifyBusiness, SuspendBusiness and
 * ReinstateBusiness, in bypass(), like status itself. A reinstated business
 * goes back to verified only if it had been verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->timestamp('verified_at')->nullable()->after('status');
            $table->timestamp('suspended_at')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn(['verified_at', 'suspended_at']);
        });
    }
};
