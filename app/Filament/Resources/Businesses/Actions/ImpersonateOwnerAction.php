<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Actions;

use App\Actions\Admin\ImpersonationRefused;
use App\Actions\Admin\StartImpersonation;
use App\Enums\PlatformRole;
use App\Filament\Resources\Businesses\Tables\BusinessesTable;
use App\Models\Business;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * "Sign in as the owner" on a business's page (CHW-144, A1): one of its
 * owners, never an anonymised account or a platform admin, after the admin
 * types their own password again. StartImpersonation checks it all again and
 * records it; the admin then reads the business app as the owner does.
 */
final class ImpersonateOwnerAction
{
    public static function make(): Action
    {
        return Action::make('impersonate')
            ->label(__('Sign in as the owner'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->authorize(fn (Business $record): bool => Gate::allows('impersonate', $record))
            ->visible(fn (Business $record): bool => ! BusinessesTable::archivedAt($record) instanceof CarbonInterface && self::owners($record)->isNotEmpty())
            ->modalDescription(__('You see the business app as the owner does, read-only, for :minutes minutes. Starting and ending are kept in the audit log.', [
                'minutes' => (int) config('punchcard.admin.impersonation_minutes'),
            ]))
            ->schema([
                Select::make('owner')->label(__('Owner'))
                    ->options(fn (Business $record): array => self::owners($record)->mapWithKeys(fn (User $owner): array => [$owner->id => $owner->name.' ('.$owner->email.')'])->all())
                    ->required(),
                TextInput::make('password')->label(__('Your password'))->password()->currentPassword()->required(),
            ])
            ->action(function (Business $record, array $data, Action $action): void {
                $admin = Auth::user();
                $owner = self::owners($record)->firstWhere('id', (int) $data['owner']);

                try {
                    if (! $admin instanceof User || ! $owner instanceof User) {
                        throw new ImpersonationRefused(__('That person is not an owner of this business.'));
                    }

                    app(StartImpersonation::class)->handle($admin, $record, $owner, session()->driver());
                } catch (ImpersonationRefused $refused) {
                    Notification::make()->danger()->title($refused->getMessage())->send();

                    return;
                }

                $action->redirect(route('dashboard'));
            });
    }

    /**
     * Its owners one may sign in as: real people, not platform admins.
     *
     * @return Collection<int, User>
     */
    private static function owners(Business $record): Collection
    {
        return $record->owners()->notAnonymised()->get()
            ->reject(fn (User $owner): bool => $owner->hasRole(PlatformRole::Admin->value))
            ->values();
    }
}
