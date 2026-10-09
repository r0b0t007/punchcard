<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Pages;

use App\Enums\BusinessStatus;
use App\Filament\Resources\Businesses\BusinessResource;
use App\Models\Business;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** The businesses list: the verification queue (pending ones) first, then each status, then all. */
class ListBusinesses extends ListRecords
{
    protected static string $resource = BusinessResource::class;

    public function getTabs(): array
    {
        return [
            'queue' => $this->status(__('Verification queue'), BusinessStatus::Pending)
                ->badge(Business::query()->where('status', BusinessStatus::Pending)->count() ?: null),
            'verified' => $this->status(__('Verified'), BusinessStatus::Verified),
            'suspended' => $this->status(__('Suspended'), BusinessStatus::Suspended),
            'all' => Tab::make(__('All')),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'queue';
    }

    private function status(string $label, BusinessStatus $status): Tab
    {
        return Tab::make($label)->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status));
    }
}
