<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Actions;

use App\Actions\Verification\DocumentRefused;
use App\Actions\Verification\RequestNewDocuments;
use App\Enums\BusinessStatus;
use App\Filament\Concerns\NotifiesRefusals;
use App\Filament\Resources\Businesses\Tables\BusinessesTable;
use App\Models\Business;
use App\Models\BusinessDocument;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * "Ask for new documents" on a business's page (CHW-145, A1): the platform
 * admin picks which waiting documents won't do and says why
 * (RequestNewDocuments). Offered on a pending, open business with documents
 * waiting; authorized as verifying it is.
 */
final class RequestNewDocumentsAction
{
    use NotifiesRefusals;

    public static function make(): Action
    {
        return Action::make('requestNewDocuments')
            ->label(__('Ask for new documents'))
            ->icon(Heroicon::OutlinedDocumentArrowUp)
            ->color('warning')
            ->authorize(fn (Business $record): bool => Gate::allows('verify', $record))
            ->visible(fn (Business $record): bool => $record->status === BusinessStatus::Pending
                && ! BusinessesTable::archivedAt($record) instanceof CarbonInterface && self::waiting($record) !== [])
            ->modalDescription(__('The owners get an email with your reason and see it on their verification page. The business stays pending.'))
            ->schema([
                // The documents on the page, by id: one replaced since is refused, never rejected unseen.
                CheckboxList::make('documents')->label(__('Documents'))->options(fn (Business $record): array => self::waiting($record))->required(),
                Textarea::make('reason')->label(__('Reason'))->helperText(__('The owners read it as you write it. Never copy a number or a name from the document.'))->required()->maxLength(1000),
            ])
            ->action(fn (Business $record, array $data) => self::notifying(function () use ($record, $data): string {
                $ids = array_values(array_map(intval(...), (array) $data['documents']));
                app(RequestNewDocuments::class)->handle($record, $ids, (string) $data['reason']);
                $record->refresh();

                return __('The owners of :business are asked for new documents.', ['business' => $record->name]);
            }, DocumentRefused::class));
    }

    /**
     * The documents waiting, by id, labelled by kind.
     *
     * @return array<int, string>
     */
    private static function waiting(Business $record): array
    {
        return $record->documents->filter(fn (BusinessDocument $document): bool => $document->isWaiting())
            ->mapWithKeys(fn (BusinessDocument $document): array => [$document->id => $document->kind->label()])
            ->all();
    }
}
