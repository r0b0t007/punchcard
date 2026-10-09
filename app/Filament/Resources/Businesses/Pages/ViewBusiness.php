<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Pages;

use App\Filament\Resources\Businesses\Actions\BusinessStatusActions;
use App\Filament\Resources\Businesses\BusinessResource;
use Filament\Resources\Pages\ViewRecord;

/** One business (BusinessInfolist), with its status actions in the header. */
class ViewBusiness extends ViewRecord
{
    protected static string $resource = BusinessResource::class;

    protected function getHeaderActions(): array
    {
        return BusinessStatusActions::all();
    }
}
