<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an email link is for (CHW-23): signing in, or confirming it's you to
 * a signed-in account without a password (settings). One never does the
 * other's job (LoginLink::spend).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_links', function (Blueprint $table): void {
            $table->string('purpose', 16)->default('sign-in');
        });
    }

    public function down(): void
    {
        Schema::table('login_links', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
