<?php

declare(strict_types=1);

namespace App\Actions\Fraud;

use App\Enums\StamperStatus;
use App\Models\Business;
use App\Models\Stamper;
use App\Models\Tap;
use App\Notifications\StamperTappedWhileOffMail;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells a business's owners that someone tapped one of its switched-off
 * stampers (CHW-36): a missing stamper may be in someone's hands. Only if it
 * is still off, and at most once every QUIET_MINUTES per stamper, so a
 * stamper tapped again and again sends one email; a failed send is owed
 * again when the job retries. Not for a suspended or closed business. Read
 * in bypass(): a queued job acts for no user.
 */
final readonly class AlertDisabledStamperTap
{
    public const int QUIET_MINUTES = 60;

    public function __construct(private TenantContext $context) {}

    public function handle(int $stamperId, int $tapId): void
    {
        $this->context->bypass(function () use ($stamperId, $tapId): void {
            $stamper = Stamper::query()->current()->with(['business', 'location'])->whereKey($stamperId)->first();
            $tap = Tap::query()->whereKey($tapId)->first(['id', 'created_at']);

            if (! $stamper instanceof Stamper || $stamper->status !== StamperStatus::Disabled || $tap?->created_at === null
                || ! Business::query()->operating()->whereKey($stamper->business_id)->exists()) {
                return;
            }

            $key = 'fraud-alert:stamper:'.$stamper->id;

            if (! Cache::add($key, true, now()->addMinutes(self::QUIET_MINUTES))) {
                return;
            }

            try {
                Notification::send($stamper->business->owners()->get(), new StamperTappedWhileOffMail(
                    stamper: $stamper->label ?? __('Stamper :id', ['id' => $stamper->id]),
                    site: $stamper->location->name ?? '',
                    tappedAt: $tap->created_at->toImmutable(),
                    timezone: LocalMoment::timezoneOf($stamper->location),
                ));
            } catch (Throwable $failed) {
                Cache::forget($key);

                throw $failed;
            }
        });
    }
}
