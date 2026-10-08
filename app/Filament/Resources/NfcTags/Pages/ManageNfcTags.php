<?php

declare(strict_types=1);

namespace App\Filament\Resources\NfcTags\Pages;

use App\Filament\Resources\NfcTags\NfcTagResource;
use Filament\Resources\Pages\ManageRecords;

/** The tag provisioning page: the table and its actions (NfcTagsTable), nothing else. */
class ManageNfcTags extends ManageRecords
{
    protected static string $resource = NfcTagResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
