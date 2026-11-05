<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Actions;

use App\Actions\Admin\BusinessStatusRefused;
use App\Actions\Admin\SetAutomationsAccess;
use App\Features\Automations;
use App\Filament\Concerns\NotifiesRefusals;
use App\Filament\Resources\Businesses\Tables\BusinessesTable;
use App\Models\Business;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;

/**
 * "Allow automations" or "Stop automations" on a business's page (CHW-46,
 * A1): the Premium feature, by hand until plans and billing decide it
 * (CHW-52). Never offered for an archived business, and refused for one.
 */
final class AutomationsAccessActions
{
    use NotifiesRefusals;

    /** @return list<Action> */
    public static function all(): array
    {
        return [self::make(true), self::make(false)];
    }

    private static function make(bool $allow): Action
    {
        [$name, $label, $icon, $description, $done] = $allow
            ? ['allowAutomations', __('Allow automations'), Heroicon::OutlinedBolt,
                __('Its owner can turn on win-back, birthday and reward reminder messages. Kept in the audit log.'),
                fn (string $business): string => __('Automations allowed for :business.', ['business' => $business])]
            : ['stopAutomations', __('Stop automations'), Heroicon::OutlinedBoltSlash,
                __('Nothing more is sent. Its automations stay as the owner set them up. Kept in the audit log.'),
                fn (string $business): string => __('Automations stopped for :business.', ['business' => $business])];

        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->authorize(fn (Business $record): bool => Gate::allows('setAutomationsAccess', $record))
            ->visible(fn (Business $record): bool => ! BusinessesTable::archivedAt($record) instanceof CarbonInterface
                && Feature::for($record)->active(Automations::class) !== $allow)
            ->requiresConfirmation()
            ->modalDescription($description)
            ->action(fn (Business $record) => self::notifying(function () use ($record, $allow, $done): string {
                app(SetAutomationsAccess::class)->handle($record, $allow);

                return $done($record->name);
            }, BusinessStatusRefused::class));
    }
}
