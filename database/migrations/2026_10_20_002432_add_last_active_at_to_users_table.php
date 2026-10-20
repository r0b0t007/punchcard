<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When an account was last used, and when it was warned it would go for
 * lack of use (CHW-58, docs/privacy/retention.md): what the inactivity rule
 * reads. RecordActivity keeps last_active_at, and clears the warning.
 *
 * Accounts from before it start from what is known: the latest of when they
 * signed up, their last stamp proving they were there, their last session,
 * passkey sign-in and redemption. Never an anonymised account: there is
 * nobody behind it to warn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('last_active_at')->nullable()->index();
            $table->timestamp('inactivity_warned_at')->nullable();
        });

        $this->backfill();
    }

    /** The latest known activity of each account from before the column (public for its test). */
    public function backfill(): void
    {
        $sources = [
            DB::table('users')->pluck('created_at', 'id'),
            DB::table('card_enrollments')->whereNotNull('last_stamp_at')->groupBy('user_id')->selectRaw('user_id, max(last_stamp_at) as at')->pluck('at', 'user_id'),
            DB::table('sessions')->whereNotNull('user_id')->groupBy('user_id')->selectRaw('user_id, max(last_activity) as at')->pluck('at', 'user_id'),
            DB::table('passkeys')->whereNotNull('last_used_at')->groupBy('user_id')->selectRaw('user_id, max(last_used_at) as at')->pluck('at', 'user_id'),
            DB::table('rewards')->join('card_enrollments', 'card_enrollments.id', '=', 'rewards.enrollment_id')->whereNotNull('rewards.redeemed_at')
                ->groupBy('card_enrollments.user_id')->selectRaw('card_enrollments.user_id, max(rewards.redeemed_at) as at')->pluck('at', 'user_id'),
        ];

        /** @var array<int, int> $latest user id => unix time */
        $latest = [];

        foreach ($sources as $source) {
            foreach ($source as $userId => $at) {
                if ($at === null) {
                    continue;
                }

                $userId = (int) $userId;
                // A session's last_activity is already a unix time; the others are UTC timestamps.
                $time = is_numeric($at) ? (int) $at : Carbon::parse((string) $at, 'UTC')->getTimestamp();
                $latest[$userId] = max($latest[$userId] ?? 0, $time);
            }
        }

        foreach ($latest as $userId => $time) {
            $at = Carbon::createFromTimestampUTC($time);

            DB::table('users')->where('id', $userId)->whereNull('anonymised_at')
                ->where(fn ($query) => $query->whereNull('last_active_at')->orWhere('last_active_at', '<', $at))
                ->update(['last_active_at' => $at]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['last_active_at']);
            $table->dropColumn(['last_active_at', 'inactivity_warned_at']);
        });
    }
};
