<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Tables;

use App\Filament\AdminTime;
use App\Filament\Resources\Businesses\Actions\BusinessStatusActions;
use App\Models\Business;
use App\Models\Location;
use App\Models\Tap;
use App\Support\Tenancy\PlatformBuilder;
use App\Support\Tenancy\TenantBuilder;
use Carbon\CarbonInterface;
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
                ->withMax(['taps' => self::accepted(...)], 'created_at'))
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('slug')->label(__('Slug'))->searchable()->fontFamily('mono')->toggleable(),
                TextColumn::make('organization.name')->label(__('Organization'))
                    ->description(fn (Business $record): string => $record->organization->type->getLabel()),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('owners.email')->label(__('Owners'))->searchable()->listWithLineBreaks()->placeholder(__('None')),
                TextColumn::make('open_locations_count')->label(__('Open sites'))->numeric(),
                TextColumn::make('current_stampers_count')->label(__('Stampers'))->numeric(),
                TextColumn::make('taps_max_created_at')->label(__('Last tap'))->dateTime(AdminTime::FORMAT)
                    ->placeholder(__('None in the last :days days', ['days' => config('punchcard.taps.retention_days')])),
                TextColumn::make('archived')->label(__('Archived'))->dateTime(AdminTime::FORMAT)->placeholder(__('No'))
                    ->state(fn (Business $record): ?CarbonInterface => self::archivedAt($record)),
                TextColumn::make('created_at')->label(__('Created'))->dateTime(AdminTime::FORMAT)->sortable(),
            ])
            ->recordActions(BusinessStatusActions::all());
    }

    /** When the business, or else its organization, was archived; null while both are open. */
    public static function archivedAt(Business $business): ?CarbonInterface
    {
        return $business->archived_at ?? $business->organization->archived_at;
    }

    /** @param  TenantBuilder<Location>  $locations */
    private static function open(TenantBuilder $locations): void
    {
        $locations->open();
    }

    /** @param  PlatformBuilder<Tap>  $taps */
    private static function accepted(PlatformBuilder $taps): void
    {
        $taps->accepted();
    }
}
