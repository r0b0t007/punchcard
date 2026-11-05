<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Automations\SendAutomationMessages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * One batch of a business's automation messages (CHW-46), queued by
 * RunAutomations in the business's tenant (QueuedTenant, which fails it if
 * the business closed meanwhile). Short, inside the queue's retry_after, so
 * no second worker takes it while it runs. One try: a retry could push to a
 * phone twice.
 */
#[Tries(1)]
#[Timeout(60)]
final class PushAutomationMessages implements ShouldQueue
{
    use Queueable;

    /** @param list<int> $sendIds */
    public function __construct(public readonly array $sendIds) {}

    public function handle(SendAutomationMessages $sendAutomationMessages): void
    {
        $sendAutomationMessages->handle($this->sendIds);
    }
}
