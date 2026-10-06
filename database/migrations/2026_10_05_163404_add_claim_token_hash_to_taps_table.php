<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A signed-out customer's pending taps are found by the session they were
 * made in (CHW-142): the tap keeps the sha256 of that session's claim token,
 * never the token. The session payload no longer lists them, so a request
 * writing back an older payload can't lose them. Null once the tap is no
 * longer waiting in that session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taps', function (Blueprint $table): void {
            $table->char('claim_token_hash', 64)->nullable()->after('user_id');
            $table->index(['claim_token_hash', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('taps', function (Blueprint $table): void {
            $table->dropIndex(['claim_token_hash', 'status']);
            $table->dropColumn('claim_token_hash');
        });
    }
};
