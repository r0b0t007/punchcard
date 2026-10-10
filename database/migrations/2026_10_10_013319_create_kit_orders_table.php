<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stamper kit orders (CHW-31): where to ship a business's free starter kit,
 * requested as the onboarding wizard's last step. Site data, like stampers:
 * composite foreign keys keep the order in its business's organization and
 * at one of its locations. One requested order per business at a time; its
 * fulfilment and tracking come with CHW-57.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kit_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('location_id')->nullable();
            $table->string('recipient_name', 120);
            $table->string('phone', 32);
            $table->string('address', 255);
            $table->string('city', 120);
            $table->string('postal_code', 16)->nullable();
            $table->char('country', 2)->default('MA');
            $table->string('status', 16)->default('requested');
            $table->timestamps();

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->foreign(['location_id', 'business_id'])
                ->references(['id', 'business_id'])->on('locations')
                ->noActionOnDelete();
            $table->index(['business_id', 'organization_id']);
        });

        DB::statement("create unique index kit_orders_one_requested on kit_orders (business_id) where status = 'requested'");

        // Widened as fulfilment adds statuses (CHW-57).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table kit_orders add constraint kit_orders_status_check check (status in ('requested'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kit_orders');
    }
};
