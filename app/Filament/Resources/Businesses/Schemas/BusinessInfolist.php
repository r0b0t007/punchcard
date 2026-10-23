<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Schemas;

use App\Actions\Admin\StopImpersonation;
use App\Actions\Verification\ReadDocument;
use App\Enums\BusinessRole;
use App\Enums\DocumentKind;
use App\Filament\AdminTime;
use App\Filament\Resources\Businesses\Tables\BusinessesTable;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\Location;
use App\Models\User;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

/**
 * One business for the platform admin (CHW-34, A1): its details, its people
 * and what they may reach, its sites, the stampers there now, its
 * verification documents with a short-lived link to each (CHW-145), and the
 * latest of what the platform admin did to it (the audit log). Read-only; the
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
            Section::make(__('Verification documents'))
                ->description(__('Each link works for :minutes minutes, and every look is in the audit log.', ['minutes' => ReadDocument::LINK_MINUTES]))
                ->schema([
                    RepeatableEntry::make('documents')->hiddenLabel()->columns(4)->placeholder(__('None sent'))
                        ->state(fn (Business $record): array => $record->documents->sortByDesc('id')->values()->all())
                        ->schema([
                            TextEntry::make('kind')->label(__('Document'))->formatStateUsing(fn (DocumentKind $state): string => $state->label()),
                            TextEntry::make('status')->label(__('Status'))->badge(),
                            TextEntry::make('size')->label(__('Size'))->formatStateUsing(fn (int $state): string => (string) Number::fileSize($state, maxPrecision: 1)),
                            TextEntry::make('open')->label(__('File'))->state(__('View document'))->color('primary')
                                ->url(fn (BusinessDocument $record): string => ReadDocument::link($record))->openUrlInNewTab(),
                            TextEntry::make('created_at')->label(__('Sent'))->dateTime($at),
                            TextEntry::make('decided_at')->label(__('Decided'))->dateTime($at)->placeholder('—'),
                            TextEntry::make('deletes_at')->label(__('Deleted on'))->dateTime($at)->placeholder('—'),
                            TextEntry::make('rejection_reason')->label(__('Reason'))->placeholder('—'),
                        ]),
                ]),
            Section::make(__('Audit log'))->description(__('The latest :count entries.', ['count' => 20]))->schema([
                RepeatableEntry::make('recentAuditLogs')->hiddenLabel()->columns(4)->placeholder(__('None'))
                    ->schema([
                        TextEntry::make('created_at')->label(__('When'))->dateTime($at),
                        TextEntry::make('action')->label(__('Action'))->fontFamily('mono'),
                        TextEntry::make('actor_label')->label(__('By')),
                        TextEntry::make('reason')->label(__('Reason'))->placeholder('—')
                            // An impersonation's end is a code (CHW-144): read in the admin's language.
                            ->formatStateUsing(fn (string $state, AuditLog $record): string => $record->action === 'impersonation.ended' ? StopImpersonation::label($state) : $state),
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
