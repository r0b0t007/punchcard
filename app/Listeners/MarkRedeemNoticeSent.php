<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Reward;
use App\Notifications\RewardRedeemed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\Events\NotificationSent;
use Minishlink\WebPush\MessageSentReport;

/**
 * The customer was told staff redeemed their reward (CHW-228) once a phone's
 * push service accepted the notification: My cards then shows no notice for
 * it. A push every phone refused leaves the notice. In bypass(), on the one
 * reward the notification names.
 */
final readonly class MarkRedeemNoticeSent
{
    public function __construct(private TenantContext $context) {}

    public function handle(NotificationSent $event): void
    {
        if (! $event->notification instanceof RewardRedeemed || ! is_array($event->response)) {
            return;
        }

        $accepted = array_filter($event->response, fn (mixed $report): bool => $report instanceof MessageSentReport && $report->isSuccess());

        if ($accepted !== []) {
            $this->context->bypass(fn () => Reward::query()->whereKey($event->notification->rewardId)->whereNull('redeem_notice_at')
                ->update(['redeem_notice_at' => now()]));
        }
    }
}
