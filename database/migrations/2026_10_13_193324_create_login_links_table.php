<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The email sign-in links sent (CHW-23): the URL is signed and good for 15
 * minutes; this row makes it good once. Pruned after a day (LoginLink).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_links', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_links');
    }
};
