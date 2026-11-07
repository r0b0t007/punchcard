<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Notifications\CustomerExportReadyMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Exports of customers and stamps (CHW-49) are kept a day: their link's life (CustomerExportReadyMail::LINK_HOURS),
 * then deleted, hourly (routes/console.php). Counts only, never whose.
 */
final readonly class DeleteExpiredExports
{
    /** @return array{deleted: int} */
    public function handle(CarbonImmutable $now): array
    {
        $disk = Storage::disk(BuildCustomerExport::DISK);
        $before = $now->subHours(CustomerExportReadyMail::LINK_HOURS)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles() as $file) {
            if ($disk->lastModified($file) <= $before && $disk->delete($file)) {
                $deleted++;
            }
        }

        return ['deleted' => $deleted];
    }
}
