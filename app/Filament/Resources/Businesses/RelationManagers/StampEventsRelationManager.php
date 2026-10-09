<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\RelationManagers;

use App\Enums\StampSource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A business's stamp ledger (CHW-34, A1), newest first, for the platform
 * admin to read: never to change (the ledger is append-only, and
 * App\Policies\Invariants refuses it to the admin too). Only this business's
 * events: the relation scopes them.
 */
class StampEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'stampEvents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Stamp history');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['location', 'stamper', 'staff']))
            ->defaultSort('id', 'desc')
            ->columns([
                HistoryColumns::when(),
                TextColumn::make('location.name')->label(__('Location')),
                TextColumn::make('source')->label(__('Source'))->badge(),
                TextColumn::make('qty')->label(__('Stamps'))->numeric(),
                HistoryColumns::stamper(),
                TextColumn::make('staff.email')->label(__('Staff member'))->placeholder('—'),
                TextColumn::make('reason')->label(__('Reason'))->placeholder('—')->wrap(),
            ])
            ->filters([
                SelectFilter::make('source')->label(__('Source'))->options(StampSource::class),
            ]);
    }
}
