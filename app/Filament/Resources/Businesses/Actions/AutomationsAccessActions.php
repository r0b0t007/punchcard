<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Actions;

use App\Actions\Admin\SetAutomationsAccess;
use App\Features\Automations;
use App\Filament\Resources\Businesses\Tables\BusinessesTable;
use App\Models\Business;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;

/**
 * "Allow automations" or "Stop automations" on a business's page (CHW-46,
 * A1): the Premium feature, by hand until plans and billing decide it
 * (CHW-52). Never offered for an archived business.
 */
final class AutomationsAccessActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [self::make(true), self::make(false)];
    }

    private static function make(bool $allow): Action
    {
        return Action::make($allow ? 'allowAutomations' : 'stopAutomations')
            ->label($allow ? __('Allow automations') : __('Stop automations'))
            ->icon($allow ? Heroicon::OutlinedBolt : Heroicon::OutlinedBoltSlash)
            ->color('gray')
            ->authorize(fn (Business $record): bool => Gate::allows('setAutomationsAccess', $record))
            ->visible(fn (Business $record): bool => ! BusinessesTable::archivedAt($record) instanceof CarbonInterface
                && Feature::for($record)->active(Automations::class) !== $allow)
            ->requiresConfirmation()
            ->modalDescription($allow
                ? __('Its owner can turn on win-back, birthday and reward reminder messages. Kept in the audit log.')
                : __('Nothing more is sent. Its automations stay as the owner set them up. Kept in the audit log.'))
            ->action(function (Business $record) use ($allow): void {
                app(SetAutomationsAccess::class)->handle($record, $allow);

                Notification::make()->success()->title($allow
                    ? __('Automations allowed for :business.', ['business' => $record->name])
                    : __('Automations stopped for :business.', ['business' => $record->name]))->send();
            });
    }
}
