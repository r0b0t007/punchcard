<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Customers\BuildCustomerExport;
use App\Models\Business;
use App\Models\User;
use App\Notifications\CustomerExportFailedMail;
use App\Notifications\CustomerExportReadyMail;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * A business's customers and stamps as CSV (CHW-49), made on the queue in its tenant (QueuedTenant: a business
 * suspended or closed meanwhile fails it), then mailed to the owner who asked as a link that works a day, for them
 * alone (CustomerExportReadyMail). Headers in their language. Within the queue's retry_after (config/queue.php);
 * tried twice, then the owner is told to ask again. An account gone meanwhile gets nothing.
 */
#[Tries(2)]
#[Timeout(80)]
final class ExportCustomers implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $businessId,
        public readonly int $ownerId,
        public readonly string $from,
        public readonly string $to,
    ) {}

    public function handle(BuildCustomerExport $buildCustomerExport): void
    {
        $owner = $this->owner();

        if (! $owner instanceof User) {
            return;
        }

        $made = $buildCustomerExport->handle($this->businessId, $this->from, $this->to, $owner->preferredLocale() ?? (string) config('app.locale'), CarbonImmutable::now());
        $owner->notify(new CustomerExportReadyMail($this->businessId, $this->businessName(), $made['export'], $this->from, $this->to, $made['customers'], $made['stamps']));
    }

    public function failed(?Throwable $exception): void
    {
        $this->owner()?->notify(new CustomerExportFailedMail($this->businessName()));
    }

    private function businessName(): string
    {
        return (string) app(TenantContext::class)->bypass(fn (): mixed => Business::query()->whereKey($this->businessId)->value('name'));
    }

    private function owner(): ?User
    {
        return app(TenantContext::class)->bypass(fn (): ?User => User::query()->notAnonymised()->find($this->ownerId));
    }
}
