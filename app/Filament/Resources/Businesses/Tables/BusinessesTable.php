<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Tables;

use App\Enums\OrganizationType;
use App\Filament\Resources\Businesses\Actions\BusinessStatusActions;
use App\Models\Business;
use App\Models\Location;
use App\Support\Tenancy\TenantBuilder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The businesses table (CHW-34, A1): who runs each business, how many open
 * sites and stampers it has, when it was last tapped, and its status, with
 * the status actions on each row. Counts and the last tap come in the list
 * query, never a query per row.
 */
final class BusinessesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['organization', 'owners'])
                ->withCount(['locations as open_locations_count' => self::open(...), 'currentStampers'])
                ->withMax('taps', 'created_at'))
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('slug')->label(__('Slug'))->searchable()->fontFamily('mono')->toggleable(),
                TextColumn::make('organization.name')->label(__('Organization'))
                    ->description(fn (Business $record): string => self::typeLabel($record->organization->type)),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('owners.email')->label(__('Owners'))->searchable()->listWithLineBreaks()->placeholder(__('None')),
                TextColumn::make('open_locations_count')->label(__('Open sites'))->numeric(),
                TextColumn::make('current_stampers_count')->label(__('Stampers'))->numeric(),
                TextColumn::make('taps_max_created_at')->label(__('Last tap'))->dateTime()->placeholder(__('Never')),
                TextColumn::make('created_at')->label(__('Created'))->dateTime()->sortable(),
            ])
            ->recordActions(BusinessStatusActions::all());
    }

    /** @param  TenantBuilder<Location>  $locations */
    private static function open(TenantBuilder $locations): void
    {
        $locations->open();
    }

    public static function typeLabel(OrganizationType $type): string
    {
        return match ($type) {
            OrganizationType::Independent => __('Independent'),
            OrganizationType::Chain => __('Chain'),
            OrganizationType::Franchise => __('Franchise'),
        };
    }
}
