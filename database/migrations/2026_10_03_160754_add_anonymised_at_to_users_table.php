<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an account with stamp history was deleted (CHW-139): the row stays so
 * the permanent stamp ledger keeps pointing at it, but everything that
 * identified the person is scrubbed and nobody can sign in to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('anonymised_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('anonymised_at');
        });
    }
};
