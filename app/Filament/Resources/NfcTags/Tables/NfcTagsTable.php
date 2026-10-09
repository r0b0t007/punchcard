<?php

declare(strict_types=1);

namespace App\Filament\Resources\NfcTags\Tables;

use App\Actions\Stampers\MoveStamper;
use App\Actions\Stampers\RecordRekey;
use App\Actions\Stampers\RegisterStamper;
use App\Actions\Stampers\RetireTag;
use App\Actions\Stampers\SetStamperStatus;
use App\Actions\Stampers\SiteName;
use App\Actions\Stampers\StamperRefused;
use App\Enums\StamperStatus;
use App\Filament\Concerns\NotifiesRefusals;
use App\Filament\Resources\Businesses\Tables\BusinessesTable;
use App\Models\Business;
use App\Models\Location;
use App\Models\NfcTag;
use App\Models\Stamper;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The tag provisioning table (CHW-138, A2): each tag, its key version,
 * counter, current stamper and last tap (any tap, rejected ones included:
 * it shows the tag is being reached), and the runbook's actions
 * (docs/runbooks/stamper-keys.md). Each action is authorized by its own
 * ability on NfcTag, which only the platform admin passes (Gate::before in
 * the admin panel), calls the same Action as its command, and shows a
 * refusal as it is. No column, form or message carries key material.
 */
final class NfcTagsTable
{
    use NotifiesRefusals;

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['currentStamper.business', 'currentStamper.location'])->withMax('taps', 'created_at'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('uid')->label(__('UID'))->searchable()->copyable()->fontFamily('mono'),
                TextColumn::make('key_version')->label(__('Key version'))->numeric(),
                TextColumn::make('last_counter')->label(__('Counter'))->numeric(),
                TextColumn::make('currentStamper.business.name')->label(__('Business'))->placeholder(__('Unassigned')),
                TextColumn::make('currentStamper.location.name')->label(__('Location')),
                TextColumn::make('currentStamper.label')->label(__('Label')),
                TextColumn::make('currentStamper.status')->label(__('Stamper'))->badge(),
                TextColumn::make('taps_max_created_at')->label(__('Last tap'))->dateTime(BusinessesTable::DATE_TIME)
                    ->placeholder(__('None in the last :days days', ['days' => config('punchcard.taps.retention_days')])),
                TextColumn::make('retired_at')->label(__('Retired'))->dateTime()->placeholder(__('In service')),
                TextColumn::make('updated_at')->label(__('Updated'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('retired')->label(__('Retired'))->nullable()->attribute('retired_at'),
                Filter::make('unassigned')->label(__('Unassigned'))->query(fn (Builder $query): Builder => $query->whereNull('retired_at')->whereDoesntHave('currentStamper')),
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
            ->label(__('Register tag'))
            ->authorize(fn (): bool => Gate::allows('register', NfcTag::class))
            ->modalDescription(__('Provision the tag first (keys from key version 1, docs/runbooks/stamper-keys.md).'))
            ->schema([
                TextInput::make('uid')->label(__('UID, as the reader prints it'))->required(),
                ...self::siteFields(),
            ])
            ->action(fn (array $data) => self::refusing(function () use ($data): string {
                $stamper = app(RegisterStamper::class)->handle((string) $data['uid'], ...self::site($data));

                return __('Registered tag :uid at :site.', ['uid' => $stamper->tag->uid, 'site' => self::where($stamper)]);
            }));
    }

    private static function move(): Action
    {
        return Action::make('move')
            ->label(__('Move'))
            ->authorize(fn (): bool => Gate::allows('move', NfcTag::class))
            ->visible(fn (NfcTag $record): bool => $record->retired_at === null && $record->currentStamper instanceof Stamper)
            ->modalDescription(__('Within the business the stamper keeps its label and status; at another business it starts enabled and unlabelled.'))
            ->schema(self::siteFields())
            ->action(fn (NfcTag $record, array $data) => self::refusing(function () use ($record, $data): string {
                $stamper = app(MoveStamper::class)->handle($record->uid, ...self::site($data));

                return __('Moved tag :uid to :site.', ['uid' => $record->uid, 'site' => self::where($stamper)]);
            }));
    }

    private static function setStatus(StamperStatus $status): Action
    {
        $disabling = $status === StamperStatus::Disabled;

        return Action::make($disabling ? 'disable' : 'enable')
            ->label($disabling ? __('Disable') : __('Enable'))
            ->authorize(fn (): bool => Gate::allows('setStatus', NfcTag::class))
            ->visible(fn (NfcTag $record): bool => $record->currentStamper instanceof Stamper && $record->currentStamper->status !== $status)
            ->requiresConfirmation()
            ->modalDescription($disabling
                ? __('It refuses every tap and its arming is cleared: step 1 of a re-key.')
                : __('If the business had disabled it before the re-key, leave it disabled.'))
            ->action(fn (NfcTag $record) => self::refusing(fn (): string => app(SetStamperStatus::class)->handle($record->uid, $status)->summary()));
    }

    private static function rekeyed(): Action
    {
        return Action::make('rekeyed')
            ->label(__('Record re-key'))
            ->authorize(fn (): bool => Gate::allows('rekey', NfcTag::class))
            ->visible(fn (NfcTag $record): bool => $record->retired_at === null)
            ->modalDescription(__('Only once keys 2, 3, 4 and then 0 all changed on the tag, its stamper disabled. The counter is kept.'))
            ->fillForm(fn (NfcTag $record): array => ['version' => $record->key_version + 1])
            ->schema([
                TextInput::make('version')->label(__('Key version the tag now has'))->integer()->minValue(2)->required(),
                Checkbox::make('keysChanged')->label(__('Keys 2, 3, 4 and then 0 all changed on the tag to this version'))->accepted(),
            ])
            ->action(fn (NfcTag $record, array $data) => self::refusing(function () use ($record, $data): string {
                $tag = app(RecordRekey::class)->handle($record->uid, from: (int) $data['version'] - 1);
                $stamper = $tag->currentStamper;

                $done = __('Tag :uid is now at key version :version; its counter is unchanged.', ['uid' => $tag->uid, 'version' => $tag->key_version]);

                return $stamper instanceof Stamper
                    ? $done.' '.__('If you disabled stamper #:id for the re-key, enable it and test one tap; if the business had disabled it, leave it.', ['id' => $stamper->id])
                    : $done;
            }));
    }

    private static function retire(): Action
    {
        return Action::make('retire')
            ->label(__('Retire'))
            ->color('danger')
            ->authorize(fn (): bool => Gate::allows('retire', NfcTag::class))
            ->visible(fn (NfcTag $record): bool => $record->retired_at === null)
            ->requiresConfirmation()
            ->modalDescription(__('For a lost or stolen tag. It can never be assigned again; register a new tag for its replacement.'))
            ->action(fn (NfcTag $record) => self::refusing(function () use ($record): string {
                app(RetireTag::class)->handle($record->uid);

                return __('Retired tag :uid.', ['uid' => $record->uid]);
            }));
    }

    /** @return list<Select|TextInput> */
    private static function siteFields(): array
    {
        return [
            Select::make('business')
                ->label(__('Business'))
                ->searchable()
                ->getSearchResultsUsing(self::businessesMatching(...))
                ->getOptionLabelUsing(fn (mixed $value): ?string => ($business = Business::query()->unarchived()->find($value)) instanceof Business ? self::businessLabel($business) : null)
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('location', null))
                ->required(),
            Select::make('location')
                ->label(__('Location'))
                ->options(fn (Get $get): array => $get('business') === null ? [] : Location::query()
                    ->where('business_id', $get('business'))->open()->orderBy('name')->get()
                    ->mapWithKeys(fn (Location $location): array => [$location->id => SiteName::of($location)])
                    ->all())
                ->placeholder(__('Its only open location (choose one if it has several)')),
            TextInput::make('label')->label(__('Label'))->maxLength(255),
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
            Business::query()->find((int) $data['business']) ?? throw StamperRefused::siteGone(),
            $location === null ? null : Location::query()->find((int) $location) ?? throw StamperRefused::siteGone(),
            is_string($label) && $label !== '' ? $label : null,
        ];
    }

    private static function where(Stamper $stamper): string
    {
        return "{$stamper->business->name}, ".SiteName::of($stamper->location);
    }

    /**
     * The business picker's search, on the server: open businesses whose name
     * or slug matches, at most 50, so the modal never loads them all.
     *
     * @return array<int, string>
     */
    public static function businessesMatching(string $search): array
    {
        return Business::query()->unarchived()
            ->where(fn (Builder $query) => $query->whereLike('name', "%{$search}%")->orWhereLike('slug', "%{$search}%"))
            ->orderBy('name')->limit(50)->get()
            ->mapWithKeys(fn (Business $business): array => [$business->id => self::businessLabel($business)])
            ->all();
    }

    private static function businessLabel(Business $business): string
    {
        return "{$business->name} ({$business->slug})";
    }

    /**
     * Runs a tag Action and tells the admin how it went: its own words when it
     * refuses.
     *
     * @param  Closure(): string  $run  the success message
     */
    private static function refusing(Closure $run): void
    {
        self::notifying($run, StamperRefused::class);
    }
}
