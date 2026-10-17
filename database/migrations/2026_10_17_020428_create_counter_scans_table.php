<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A staff member's scan of a customer's member QR (CHW-30): who, where, by
 * whom, the code's window, until when it can be used, and the one stamp it
 * gave (one scan per stamp); the rewards it redeemed record it
 * (rewards.redeemed_scan_id). A ULID key, so a scan's address can't be guessed. One scan per code
 * at a business (business_id, user_id, member_qr_window): the database's
 * guard beside the cache's claim (MemberQrScan), which a cleared cache would
 * lift. Composite foreign keys, as on stamp_events, keep organization_id the
 * business's and location_id inside the business; the stamp_event_id key is
 * a plain one, on this table only (stamp_events keeps its triggers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counter_scans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('location_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('member_qr_window');
            $table->timestamp('scanned_at');
            $table->timestamp('expires_at');
            $table->foreignId('stamp_event_id')->nullable()->unique()->constrained('stamp_events')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->foreign(['location_id', 'business_id'])
                ->references(['id', 'business_id'])->on('locations')
                ->cascadeOnDelete();
            $table->unique(['business_id', 'user_id', 'member_qr_window']);
            $table->index(['business_id', 'organization_id']);
            $table->index(['location_id', 'business_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counter_scans');
    }
};
