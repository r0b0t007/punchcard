<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a campaign recorded who it went to (CHW-48, B10): every campaign
 * from now on does (campaign_sends), so its results show, nobody reached
 * included. One already sent without a single recorded send was sent
 * before recording began: its results page says so instead of showing
 * zeros. Told once, here, rather than guessed from its sends on every
 * read: a campaign whose pushes all failed lets its claims go, and has
 * none either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->boolean('records_sends')->default(true);
        });

        DB::table('campaigns')->where('status', 'sent')
            ->whereNotExists(fn ($sends) => $sends->select(DB::raw(1))->from('campaign_sends')->whereColumn('campaign_sends.campaign_id', 'campaigns.id'))
            ->update(['records_sends' => false]);
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('records_sends');
        });
    }
};
