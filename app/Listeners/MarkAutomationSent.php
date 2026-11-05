<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\AutomationSend;
use App\Notifications\AutomationMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\Events\NotificationSent;
use Minishlink\WebPush\MessageSentReport;

/**
 * Marks an automation message sent (CHW-46) once a phone's push service
 * accepted it: the web push channel reports each of the customer's phones,
 * and an expired or refused one is not the customer reached. Once
 * (AutomationSend::markSent), in bypass().
 */
final readonly class MarkAutomationSent
{
    public function __construct(private TenantContext $context) {}

    public function handle(NotificationSent $event): void
    {
        if (! $event->notification instanceof AutomationMessage || ! is_array($event->response)) {
            return;
        }

        $accepted = array_filter($event->response, fn (mixed $report): bool => $report instanceof MessageSentReport && $report->isSuccess());

        if ($accepted !== []) {
            $this->context->bypass(fn (): ?bool => AutomationSend::query()->find($event->notification->sendId)?->markSent(now()));
        }
    }
}
