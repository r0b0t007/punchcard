<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the platform admin verified a business, and when it was suspended
 * (CHW-34): set only by VerifyBusiness, SuspendBusiness and
 * ReinstateBusiness, in bypass(), like status itself. A reinstated business
 * goes back to verified only if it had been verified, so the businesses
 * already verified or suspended get their date here, from their last update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->timestamp('verified_at')->nullable()->after('status');
            $table->timestamp('suspended_at')->nullable()->after('verified_at');
        });

        DB::table('businesses')->where('status', 'verified')->update(['verified_at' => DB::raw('coalesce(updated_at, created_at, current_timestamp)')]);
        DB::table('businesses')->where('status', 'suspended')->update(['suspended_at' => DB::raw('coalesce(updated_at, created_at, current_timestamp)')]);
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn(['verified_at', 'suspended_at']);
        });
    }
};
