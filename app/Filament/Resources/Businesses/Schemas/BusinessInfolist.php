<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Schemas;

use App\Enums\BusinessRole;
use App\Filament\AdminTime;
use App\Filament\Resources\Businesses\Tables\BusinessesTable;
use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One business for the platform admin (CHW-34, A1): its details, its people
 * and what they may reach, its sites, the stampers there now, and the latest
 * of what the platform admin did to it (the audit log). Read-only; the
 * relations come loaded with the record (ViewBusiness).
 */
final class BusinessInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $at = AdminTime::FORMAT;

        return $schema->columns(1)->components([
            Section::make(__('Business'))->columns(3)->schema([
                TextEntry::make('name')->label(__('Name')),
                TextEntry::make('slug')->label(__('Slug'))->fontFamily('mono'),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('organization.name')->label(__('Organization'))
                    ->helperText(fn (Business $record): string => $record->organization->type->getLabel()),
                TextEntry::make('verified_at')->label(__('Verified'))->dateTime($at)->placeholder(__('Never')),
                TextEntry::make('suspended_at')->label(__('Suspended'))->dateTime($at)->placeholder(__('No')),
                TextEntry::make('created_at')->label(__('Created'))->dateTime($at),
                TextEntry::make('archived')->label(__('Archived'))->dateTime($at)->placeholder(__('No'))
                    ->state(fn (Business $record): mixed => BusinessesTable::archivedAt($record)),
            ]),
            Section::make(__('People'))->schema([
                RepeatableEntry::make('members')->hiddenLabel()->columns(3)->placeholder(__('None'))->schema([
                    TextEntry::make('email')->label(__('Email')),
                    TextEntry::make('role')->label(__('Role'))
                        ->state(fn (User $record): ?BusinessRole => self::pivot($record, 'role')),
                    TextEntry::make('site')->label(__('Site'))
                        ->state(fn (User $record, ViewRecord $livewire): string => self::siteOf($livewire->getRecord(), self::pivot($record, 'location_id'))),
                ]),
            ]),
            Section::make(__('Sites'))->schema([
                RepeatableEntry::make('locations')->hiddenLabel()->columns(3)->placeholder(__('None'))->schema([
                    TextEntry::make('name')->label(__('Name')),
                    TextEntry::make('timezone')->label(__('Timezone')),
                    TextEntry::make('archived_at')->label(__('Archived'))->dateTime($at)->placeholder(__('Open')),
                ]),
            ]),
            Section::make(__('Stampers'))->schema([
                RepeatableEntry::make('currentStampers')->hiddenLabel()->columns(4)->placeholder(__('None'))->schema([
                    TextEntry::make('tag.uid')->label(__('UID'))->fontFamily('mono')->copyable(),
                    TextEntry::make('location.name')->label(__('Location')),
                    TextEntry::make('label')->label(__('Label'))->placeholder('—'),
                    TextEntry::make('status')->label(__('Stamper'))->badge(),
                ]),
            ]),
            Section::make(__('Audit log'))->description(__('The latest :count entries.', ['count' => 20]))->schema([
                RepeatableEntry::make('recentAuditLogs')->hiddenLabel()->columns(4)->placeholder(__('None'))
                    ->schema([
                        TextEntry::make('created_at')->label(__('When'))->dateTime($at),
                        TextEntry::make('action')->label(__('Action'))->fontFamily('mono'),
                        TextEntry::make('actor_label')->label(__('By')),
                        TextEntry::make('reason')->label(__('Reason'))->placeholder('—'),
                    ]),
            ]),
        ]);
    }

    private static function pivot(User $member, string $key): mixed
    {
        $pivot = $member->getRelationValue('pivot');

        return $pivot?->getAttribute($key);
    }

    private static function siteOf(mixed $business, mixed $locationId): string
    {
        if ($locationId === null) {
            return __('Every site');
        }

        $location = $business instanceof Business ? $business->locations->firstWhere('id', $locationId) : null;

        return $location instanceof Location ? $location->name : '#'.$locationId;
    }
}
