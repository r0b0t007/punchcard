<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a business is in the onboarding wizard (CHW-31): the step reached
 * (null: the first), when it finished, and when its owner first opened the
 * printable QR stand (the dashboard checklist). Businesses that exist
 * already were set up by hand, so they count as onboarded and never enter
 * the wizard.
 *
 * The category becomes one of App\Enums\BusinessCategory: a stored value is
 * trimmed and lowercased ("Cafe " is a café), one still unknown becomes
 * "other" (no business has gone live yet, so nothing real is lost), and
 * Postgres refuses any other from now on.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array CATEGORIES = ['cafe', 'restaurant', 'bakery', 'barber', 'salon', 'beauty', 'other'];

    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('onboarding_step', 32)->nullable()->after('category');
            $table->timestamp('onboarded_at')->nullable()->after('onboarding_step');
            $table->timestamp('qr_stand_opened_at')->nullable()->after('onboarded_at');
        });

        DB::table('businesses')->update(['onboarded_at' => DB::raw('coalesce(created_at, current_timestamp)')]);
        DB::table('businesses')->whereNotNull('category')->update(['category' => DB::raw('lower(trim(category))')]);
        DB::table('businesses')->whereNotNull('category')->whereNotIn('category', self::CATEGORIES)->update(['category' => 'other']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("alter table businesses add constraint businesses_category_check check (category is null or category in ('".implode("', '", self::CATEGORIES)."'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table businesses drop constraint if exists businesses_category_check');
        }

        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn(['onboarding_step', 'onboarded_at', 'qr_stand_opened_at']);
        });
    }
};
