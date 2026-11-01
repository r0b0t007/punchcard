<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns (CHW-42, B9): a notification a business's owner sends its
 * followers. Site data, like stampers: composite foreign keys keep it in its
 * business's organization, and the card it opens in that organization. Never
 * deleted: a scheduled one is canceled (CHW-42, part 3), and the counts stay
 * for results (B10, CHW-48). business_id stays required until org-wide
 * campaigns (docs/spec.md) are built.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // The composer's, per page load: a double tap on Send, or a retried post, is the same campaign.
            $table->uuid('idempotency_key');
            $table->string('title', 40);
            $table->string('body', 120);
            $table->foreignId('card_id')->nullable();
            $table->string('segment', 16);
            $table->string('status', 16)->default('scheduled');
            $table->timestamp('send_at');
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('audience_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamps();

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->foreign(['card_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('loyalty_cards')
                ->noActionOnDelete();
            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'send_at']);
            $table->index(['status', 'send_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table campaigns
                    add constraint campaigns_segment_check check (segment in ('all', 'quiet_14d', 'top_10')),
                    add constraint campaigns_status_check check (status in ('scheduled', 'sending', 'sent', 'canceled')),
                    add constraint campaigns_sent_check check (status <> 'sent' or sent_at is not null)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
