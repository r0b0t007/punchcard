<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Actions;

use App\Actions\Admin\BusinessStatusRefused;
use App\Actions\Admin\ReinstateBusiness;
use App\Actions\Admin\SuspendBusiness;
use App\Actions\Admin\VerifyBusiness;
use App\Enums\BusinessStatus;
use App\Filament\Concerns\NotifiesRefusals;
use App\Filament\Resources\Businesses\Tables\BusinessesTable;
use App\Models\Business;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Verify, suspend and reinstate (CHW-34, A1), on the list's rows and the
 * view page. Each is authorized by its own ability on Business, which only
 * the platform admin passes (Gate::before in the admin panel), offered only
 * where it applies (never on an archived business), calls the same Action
 * as anywhere else, and shows a refusal as it is.
 */
final class BusinessStatusActions
{
    use NotifiesRefusals;

    /** @return list<Action> */
    public static function all(): array
    {
        return [self::verify(), self::suspend(), self::reinstate()];
    }

    private static function verify(): Action
    {
        return Action::make('verify')
            ->label(__('Verify'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->authorize(fn (Business $record): bool => Gate::allows('verify', $record))
            ->visible(fn (Business $record): bool => self::offered($record, $record->status === BusinessStatus::Pending))
            ->requiresConfirmation()
            ->modalDescription(__('Records that the platform reviewed this business. It changes nothing it can do.'))
            ->action(fn (Business $record) => self::change(
                $record,
                fn () => app(VerifyBusiness::class)->handle($record),
                __(':business is verified.', ['business' => $record->name]),
            ));
    }

    private static function suspend(): Action
    {
        return Action::make('suspend')
            ->label(__('Suspend'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize(fn (Business $record): bool => Gate::allows('suspend', $record))
            ->visible(fn (Business $record): bool => self::offered($record, $record->status !== BusinessStatus::Suspended))
            ->modalDescription(__('Its taps, stamps and redemptions stop and its people lose access until you reinstate it. Its stampers stay assigned.'))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->helperText(__('Kept in the audit log.'))->required()->maxLength(1000),
            ])
            ->action(fn (Business $record, array $data) => self::change(
                $record,
                fn () => app(SuspendBusiness::class)->handle($record, (string) $data['reason']),
                __(':business is suspended: its taps, stamps and redemptions stop, and its people lose access.', ['business' => $record->name]),
            ));
    }

    private static function reinstate(): Action
    {
        return Action::make('reinstate')
            ->label(__('Reinstate'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->authorize(fn (Business $record): bool => Gate::allows('reinstate', $record))
            ->visible(fn (Business $record): bool => self::offered($record, $record->status === BusinessStatus::Suspended))
            ->requiresConfirmation()
            ->modalDescription(__('It goes back to verified if it was verified, else to pending, and its counter and people work again.'))
            ->action(fn (Business $record) => self::change(
                $record,
                fn () => app(ReinstateBusiness::class)->handle($record),
                __(':business is reinstated.', ['business' => $record->name]),
            ));
    }

    /** Whether to offer the action: it applies to the status, and the business is not archived (the Action would refuse it). */
    private static function offered(Business $record, bool $applies): bool
    {
        return $applies && ! BusinessesTable::archivedAt($record) instanceof CarbonInterface;
    }

    /**
     * Runs the status Action and reports it. The record is refreshed: the
     * view page's header actions read that same instance for their visibility.
     *
     * @param  Closure(): Business  $change
     */
    private static function change(Business $record, Closure $change, string $done): void
    {
        self::notifying(function () use ($record, $change, $done): string {
            $change();
            $record->refresh();

            return $done;
        }, BusinessStatusRefused::class);
    }
}
