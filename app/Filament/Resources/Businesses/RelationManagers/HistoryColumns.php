<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\RelationManagers;

use App\Models\Location;
use App\Models\Stamper;
use App\Models\StampEvent;
use App\Models\Tap;
use Filament\Tables\Columns\TextColumn;

/**
 * The columns a business's two history tables share. Times are read at the
 * site where it happened (CLAUDE.md: stored in UTC, displayed in the
 * location's timezone), and the location column says which site that is.
 */
final class HistoryColumns
{
    public static function when(): TextColumn
    {
        return TextColumn::make('created_at')->label(__('When'))
            ->dateTime('j M Y, H:i')
            ->timezone(fn (StampEvent|Tap $record): string => self::timezoneOf($record->location));
    }

    public static function stamper(): TextColumn
    {
        return TextColumn::make('stamper_id')->label(__('Stamper'))->placeholder('—')
            ->formatStateUsing(fn (StampEvent|Tap $record): ?string => $record->stamper instanceof Stamper
                ? $record->stamper->label ?? '#'.$record->stamper->id
                : null);
    }

    private static function timezoneOf(?Location $location): string
    {
        return $location->timezone ?? (string) config('app.timezone');
    }
}
