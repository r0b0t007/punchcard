<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An anonymised account holds no organization role (CHW-308): DeleteAccount
 * now removes it, and this removes the ones kept before. Only org admins of
 * an archived organization could have one; a restore would have made
 * "Deleted user" their admin again. Nothing to put back on the way down.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('organization_user')
            ->whereIn('user_id', DB::table('users')->whereNotNull('anonymised_at')->select('id'))
            ->delete();
    }

    public function down(): void {}
};
