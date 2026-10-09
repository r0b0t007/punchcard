<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\RelationManagers;

use App\Enums\TapRejection;
use App\Enums\TapStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The taps refused at a business's stampers (CHW-34, A1), newest first, for
 * the platform admin to read: why each was refused, on which stamper, and
 * the counter it spent (a replay has none: its counter is on the tap that
 * spent it). The tap log's IP and user agent are personal data the admin
 * doesn't need here, so they stay off the screen. Rows leave with the tap
 * log's retention (taps.retention_days).
 */
class RejectedTapsRelationManager extends RelationManager
{
    protected static string $relationship = 'taps';

    /**
     * The reasons a business's rejected taps can carry. The rest never reach
     * a business: a malformed URL, an unknown tag or a failed signature is
     * not tied to one, a retired or unassigned tag has no stamper, and an
     * expired tap has its own status.
     */
    public const array REASONS = [
        TapRejection::Replay,
        TapRejection::StamperDisabled,
        TapRejection::Cooldown,
        TapRejection::DailyCap,
        TapRejection::CardInactive,
        TapRejection::NotHonoured,
        TapRejection::SiteClosed,
        TapRejection::CardMisconfigured,
        TapRejection::AlreadyRedeemed,
    ];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Rejected taps');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', TapStatus::Rejected)->with(['location', 'stamper']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->columns([
                HistoryColumns::when(),
                TextColumn::make('location.name')->label(__('Location'))->placeholder('—'),
                HistoryColumns::stamper(),
                TextColumn::make('rejection')->label(__('Reason'))->badge()->color('danger'),
                TextColumn::make('counter')->label(__('Counter'))->numeric()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('rejection')->label(__('Reason'))
                    ->options(collect(self::REASONS)->mapWithKeys(fn (TapRejection $reason): array => [$reason->value => $reason->getLabel()])->all()),
            ]);
    }
}
