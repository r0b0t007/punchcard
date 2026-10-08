<?php

declare(strict_types=1);

namespace App\Filament\Resources\NfcTags;

use App\Filament\Resources\NfcTags\Pages\ManageNfcTags;
use App\Filament\Resources\NfcTags\Tables\NfcTagsTable;
use App\Models\NfcTag;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Tag provisioning (CHW-138, spec A2): the platform admin's page over NFC
 * tags. No stock create, edit or delete (App\Policies\Invariants refuses
 * them): its actions call the tag Actions, as the punchcard:stamper and
 * punchcard:tag commands do. Never shows key material.
 */
class NfcTagResource extends Resource
{
    protected static ?string $model = NfcTag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Tag provisioning';

    protected static ?string $modelLabel = 'tag';

    protected static ?string $recordTitleAttribute = 'uid';

    public static function table(Table $table): Table
    {
        return NfcTagsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageNfcTags::route('/'),
        ];
    }
}
