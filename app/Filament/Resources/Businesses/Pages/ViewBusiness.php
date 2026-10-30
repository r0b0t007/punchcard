<?php

declare(strict_types=1);

namespace App\Filament\Resources\Businesses\Pages;

use App\Enums\DocumentStatus;
use App\Filament\Resources\Businesses\Actions\BusinessStatusActions;
use App\Filament\Resources\Businesses\Actions\ImpersonateOwnerAction;
use App\Filament\Resources\Businesses\Actions\RequestNewDocumentsAction;
use App\Filament\Resources\Businesses\BusinessResource;
use App\Models\Business;
use App\Models\BusinessDocument;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * One business (BusinessInfolist), with its status actions in the header,
 * asking for new verification documents (CHW-145), and signing in as its
 * owner (CHW-144).
 * What the page shows is loaded with the record, never a query per row.
 */
class ViewBusiness extends ViewRecord
{
    protected static string $resource = BusinessResource::class;

    /**
     * The waiting verification documents the page last showed, by id: what
     * Verify accepts (CHW-294). Set on each render, never by the browser.
     *
     * @var list<int>
     */
    #[Locked]
    public array $documentsShown = [];

    public function rendering(): void
    {
        $business = $this->getRecord();

        $this->documentsShown = $business instanceof Business ? array_values($business->documents
            ->filter(fn (BusinessDocument $document): bool => $document->status === DocumentStatus::Submitted)
            ->map(fn (BusinessDocument $document): int => $document->id)->sort()->all()) : [];
    }

    protected function resolveRecord(int|string $key): Model
    {
        $business = parent::resolveRecord($key);

        return $business instanceof Business ? $business->load([
            'organization',
            'members',
            'locations',
            'currentStampers.tag:id,uid',
            'currentStampers.location',
            'recentAuditLogs',
            'documents',
        ]) : $business;
    }

    protected function getHeaderActions(): array
    {
        return [...BusinessStatusActions::all(), RequestNewDocumentsAction::make(), ImpersonateOwnerAction::make()];
    }
}
