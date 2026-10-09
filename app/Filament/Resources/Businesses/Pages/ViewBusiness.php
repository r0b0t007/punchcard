<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Pages;

use App\Filament\Resources\Businesses\Actions\BusinessStatusActions;
use App\Filament\Resources\Businesses\BusinessResource;
use App\Models\Business;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * One business (BusinessInfolist), with its status actions in the header.
 * What the page shows is loaded with the record, never a query per row.
 */
class ViewBusiness extends ViewRecord
{
    protected static string $resource = BusinessResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        $business = parent::resolveRecord($key);

        return $business instanceof Business ? $business->load([
            'organization',
            'members',
            'locations',
            'currentStampers.tag:id,uid',
            'currentStampers.location',
            'recentAuditLogs',
        ]) : $business;
    }

    protected function getHeaderActions(): array
    {
        return BusinessStatusActions::all();
    }
}
