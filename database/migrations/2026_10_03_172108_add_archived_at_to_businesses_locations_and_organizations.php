<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archiving (CHW-139): the stamp ledger is permanent, so a business, a
 * location or an organization with history is closed by archiving it, never
 * deleted. A column, not a business status, so archiving and restoring keep
 * whatever verification or suspension the business had.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array TABLES = ['businesses', 'locations', 'organizations'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->timestamp('archived_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('archived_at');
            });
        }
    }
};
