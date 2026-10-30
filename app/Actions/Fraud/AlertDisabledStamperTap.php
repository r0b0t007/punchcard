<?php

declare(strict_types=1);

namespace App\Actions\Fraud;

use App\Actions\Fraud\Concerns\MailsOwnersOnce;
use App\Enums\StamperStatus;
use App\Models\Business;
use App\Models\Stamper;
use App\Models\Tap;
use App\Notifications\StamperTappedWhileOffMail;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;

/**
 * Tells a business's owners that someone tapped one of its switched-off
 * stampers (CHW-36): a missing stamper may be in someone's hands. Only if it
 * is still off, and at most once every QUIET_MINUTES per stamper and owner,
 * so a stamper tapped again and again sends one email (MailsOwnersOnce). Not
 * for a suspended or closed business, its organization's closing included.
 * Read in bypass(): a queued job acts for no user.
 */
final readonly class AlertDisabledStamperTap
{
    use MailsOwnersOnce;

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

            $this->mailOwnersOnce($stamper->business, 'fraud-alert:stamper:'.$stamper->id, now()->addMinutes(self::QUIET_MINUTES), new StamperTappedWhileOffMail(
                businessId: $stamper->business_id,
                label: $stamper->label,
                stamperId: $stamper->id,
                site: $stamper->location->name ?? '',
                tappedAt: $tap->created_at->toImmutable(),
                timezone: LocalMoment::timezoneOf($stamper->location),
            ));
        });
    }
}
