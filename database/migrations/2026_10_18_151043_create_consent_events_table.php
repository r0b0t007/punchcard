<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change of a customer's consent (CHW-58): what, on or off, where
 * from (a sign-up form or the settings), when. Append-only: the users
 * columns hold the current state, this the history that shows which
 * consent covered a message sent on a given day. It is the person's data:
 * it goes with the account (cascade on delete, DeleteAccount when it
 * anonymises).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->boolean('granted');
            $table->string('source');
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_events');
    }
};
