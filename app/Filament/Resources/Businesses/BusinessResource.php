<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses;

use App\Filament\Resources\Businesses\Pages\ListBusinesses;
use App\Filament\Resources\Businesses\Pages\ViewBusiness;
use App\Filament\Resources\Businesses\Schemas\BusinessInfolist;
use App\Filament\Resources\Businesses\Tables\BusinessesTable;
use App\Models\Business;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Businesses and verification (CHW-34, spec A1): the platform admin's
 * verification queue and every business's people, sites and stampers. No
 * create, edit or delete: businesses sign up themselves (CHW-31), and their
 * status changes only through verify, suspend and reinstate.
 */
class BusinessResource extends Resource
{
    protected static ?string $model = Business::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('Businesses');
    }

    public static function getModelLabel(): string
    {
        return __('business');
    }

    public static function getPluralModelLabel(): string
    {
        return __('businesses');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return BusinessesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BusinessInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBusinesses::route('/'),
            'view' => ViewBusiness::route('/{record}'),
        ];
    }
}
