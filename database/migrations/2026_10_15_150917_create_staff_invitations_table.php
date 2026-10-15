<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invitation to a business's staff (CHW-146), optionally to one location:
 * mailed as a signed link, used once (accepted_at), then kept a while for the
 * owner's list (StaffInvitation::prunable). Composite foreign keys, as on
 * business_user, keep organization_id the business's and location_id inside
 * the same business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('location_id')->nullable();
            $table->string('email');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            // An invitation never holds a location back: it goes with it.
            $table->foreign(['location_id', 'business_id'])
                ->references(['id', 'business_id'])->on('locations')
                ->cascadeOnDelete();
            $table->index(['business_id', 'organization_id']);
            $table->index(['location_id', 'business_id']);
            $table->index(['business_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
