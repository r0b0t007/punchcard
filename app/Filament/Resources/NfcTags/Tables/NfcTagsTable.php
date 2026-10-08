<?php

declare(strict_types=1);

namespace App\Filament\Resources\NfcTags\Tables;

use App\Actions\Stampers\MoveStamper;
use App\Actions\Stampers\RecordRekey;
use App\Actions\Stampers\RegisterStamper;
use App\Actions\Stampers\RetireTag;
use App\Actions\Stampers\SetStamperStatus;
use App\Actions\Stampers\StamperRefused;
use App\Enums\StamperStatus;
use App\Models\Business;
use App\Models\Location;
use App\Models\NfcTag;
use App\Models\Stamper;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The tag provisioning table (CHW-138, A2): each tag, its key version,
 * counter and current stamper, and the runbook's actions
 * (docs/runbooks/stamper-keys.md). Each action is authorized by its own
 * ability on NfcTag, which only the platform admin passes (Gate::before in
 * the admin panel), calls the same Action as its command, and shows a
 * refusal as it is. No column, form or message carries key material.
 */
final class NfcTagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['currentStamper.business', 'currentStamper.location']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('uid')->label('UID')->searchable()->copyable()->fontFamily('mono'),
                TextColumn::make('key_version')->label('Key version')->numeric(),
                TextColumn::make('last_counter')->label('Counter')->numeric(),
                TextColumn::make('currentStamper.business.name')->label('Business')->placeholder('Unassigned'),
                TextColumn::make('currentStamper.location.name')->label('Location'),
                TextColumn::make('currentStamper.label')->label('Label'),
                TextColumn::make('currentStamper.status')->label('Stamper')->badge(),
                TextColumn::make('retired_at')->label('Retired')->dateTime()->placeholder('In service'),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('retired')->nullable()->attribute('retired_at'),
                Filter::make('unassigned')->query(fn (Builder $query): Builder => $query->whereNull('retired_at')->whereDoesntHave('currentStamper')),
            ])
            ->headerActions([self::register()])
            ->recordActions([
                self::move(),
                self::setStatus(StamperStatus::Disabled),
                self::setStatus(StamperStatus::Active),
                self::rekeyed(),
                self::retire(),
            ]);
    }

    private static function register(): Action
    {
        return Action::make('register')
            ->label('Register tag')
            ->authorize(fn (): bool => Gate::allows('register', NfcTag::class))
            ->modalDescription('Provision the tag first (keys from key version 1, docs/runbooks/stamper-keys.md).')
            ->schema([
                TextInput::make('uid')->label('UID, as the reader prints it')->required(),
                ...self::siteFields(),
            ])
            ->action(fn (array $data) => self::refusing(function () use ($data): string {
                $stamper = app(RegisterStamper::class)->handle((string) $data['uid'], ...self::site($data));

                return "Registered tag {$stamper->tag->uid} at ".self::where($stamper).'.';
            }));
    }

    private static function move(): Action
    {
        return Action::make('move')
            ->authorize(fn (): bool => Gate::allows('move', NfcTag::class))
            ->visible(fn (NfcTag $record): bool => $record->retired_at === null && $record->currentStamper instanceof Stamper)
            ->modalDescription('Within the business the stamper keeps its label and status; at another business it starts enabled and unlabelled.')
            ->schema(self::siteFields())
            ->action(fn (NfcTag $record, array $data) => self::refusing(function () use ($record, $data): string {
                $stamper = app(MoveStamper::class)->handle($record->uid, ...self::site($data));

                return "Moved tag {$record->uid} to ".self::where($stamper).'.';
            }));
    }

    private static function setStatus(StamperStatus $status): Action
    {
        $disabling = $status === StamperStatus::Disabled;

        return Action::make($disabling ? 'disable' : 'enable')
            ->authorize(fn (): bool => Gate::allows('setStatus', NfcTag::class))
            ->visible(fn (NfcTag $record): bool => $record->currentStamper instanceof Stamper && $record->currentStamper->status !== $status)
            ->requiresConfirmation()
            ->modalDescription($disabling
                ? 'It refuses every tap and its arming is cleared: step 1 of a re-key.'
                : 'If the business had disabled it before the re-key, leave it disabled.')
            ->action(fn (NfcTag $record) => self::refusing(function () use ($record, $status): string {
                $change = app(SetStamperStatus::class)->handle($record->uid, $status);

                return 'Stamper #'.$change->stamper->id.' is '.($status === StamperStatus::Active ? 'enabled' : 'disabled').'.';
            }));
    }

    private static function rekeyed(): Action
    {
        return Action::make('rekeyed')
            ->label('Record re-key')
            ->authorize(fn (): bool => Gate::allows('rekey', NfcTag::class))
            ->visible(fn (NfcTag $record): bool => $record->retired_at === null)
            ->modalDescription('Only once keys 2, 3, 4 and then 0 all changed on the tag, its stamper disabled. The counter is kept.')
            ->fillForm(fn (NfcTag $record): array => ['version' => $record->key_version + 1])
            ->schema([
                TextInput::make('version')->label('Key version the tag now has')->integer()->minValue(2)->required(),
                Checkbox::make('keysChanged')->label('Keys 2, 3, 4 and then 0 all changed on the tag to this version')->accepted(),
            ])
            ->action(fn (NfcTag $record, array $data) => self::refusing(function () use ($record, $data): string {
                $tag = app(RecordRekey::class)->handle($record->uid, from: (int) $data['version'] - 1);

                return "Tag {$tag->uid} is now at key version {$tag->key_version}.";
            }));
    }

    private static function retire(): Action
    {
        return Action::make('retire')
            ->color('danger')
            ->authorize(fn (): bool => Gate::allows('retire', NfcTag::class))
            ->visible(fn (NfcTag $record): bool => $record->retired_at === null)
            ->requiresConfirmation()
            ->modalDescription('For a lost or stolen tag. It can never be assigned again; register a new tag for its replacement.')
            ->action(fn (NfcTag $record) => self::refusing(function () use ($record): string {
                app(RetireTag::class)->handle($record->uid);

                return "Retired tag {$record->uid}.";
            }));
    }

    /** @return list<Select|TextInput> */
    private static function siteFields(): array
    {
        return [
            Select::make('business')
                ->options(fn (): array => Business::query()->unarchived()->orderBy('name')->get()
                    ->mapWithKeys(fn (Business $business): array => [$business->id => "{$business->name} ({$business->slug})"])
                    ->all())
                ->searchable()
                ->live()
                ->required(),
            Select::make('location')
                ->options(fn (Get $get): array => $get('business') === null ? [] : Location::query()
                    ->where('business_id', $get('business'))->open()->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder('Its only open location'),
            TextInput::make('label')->maxLength(255),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Business, 1: ?Location, 2: ?string}
     */
    private static function site(array $data): array
    {
        $location = $data['location'] ?? null;
        $label = $data['label'] ?? null;

        return [
            Business::query()->findOrFail((int) $data['business']),
            $location === null ? null : Location::query()->find((int) $location),
            is_string($label) && $label !== '' ? $label : null,
        ];
    }

    private static function where(Stamper $stamper): string
    {
        return "{$stamper->business->name}, {$stamper->location->name}";
    }

    /**
     * Runs a tag Action and tells the admin how it went: its own words when it
     * refuses.
     *
     * @param  Closure(): string  $run  the success message
     */
    private static function refusing(Closure $run): void
    {
        try {
            Notification::make()->success()->title($run())->send();
        } catch (StamperRefused $refused) {
            Notification::make()->danger()->title($refused->getMessage())->send();
        }
    }
}
