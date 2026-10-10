<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Pages;

use App\Enums\BusinessStatus;
use App\Filament\Resources\Businesses\BusinessResource;
use App\Models\Business;
use App\Support\Tenancy\TenantBuilder;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * The businesses list: the verification queue (pending ones) first, then
 * each status, then all. The status tabs leave out archived businesses
 * (Business::unarchived): nothing can change their status; All shows them.
 */
class ListBusinesses extends ListRecords
{
    protected static string $resource = BusinessResource::class;

    public function getTabs(): array
    {
        return [
            // Only businesses that finished setup (CHW-31): one still in the onboarding wizard has nothing to verify yet.
            'queue' => $this->status(__('Verification queue'), BusinessStatus::Pending, setUpOnly: true)
                ->badge(Business::query()->unarchived()->where('status', BusinessStatus::Pending)->whereNotNull('onboarded_at')->count() ?: null),
            'verified' => $this->status(__('Verified'), BusinessStatus::Verified),
            'suspended' => $this->status(__('Suspended'), BusinessStatus::Suspended),
            'all' => Tab::make(__('All')),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'queue';
    }

    private function status(string $label, BusinessStatus $status, bool $setUpOnly = false): Tab
    {
        return Tab::make($label)->modifyQueryUsing(function (TenantBuilder $query) use ($status, $setUpOnly): void {
            $this->unarchived($query)->where('status', $status)->when($setUpOnly, fn (TenantBuilder $setUp) => $setUp->whereNotNull('onboarded_at'));
        });
    }

    /**
     * @param  TenantBuilder<Business>  $query
     * @return TenantBuilder<Business>
     */
    private function unarchived(TenantBuilder $query): TenantBuilder
    {
        return $query->unarchived();
    }
}
