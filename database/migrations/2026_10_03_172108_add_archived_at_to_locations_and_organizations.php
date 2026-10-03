<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archiving (CHW-139): the stamp ledger is permanent, so a location or an
 * organization with history is closed by archiving it, never deleted.
 * Businesses use their status (BusinessStatus::Archived) instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable();
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
