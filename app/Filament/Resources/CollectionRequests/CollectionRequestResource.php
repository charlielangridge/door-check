<?php

namespace App\Filament\Resources\CollectionRequests;

use App\Filament\Resources\CollectionRequests\Pages\CreateCollectionRequest;
use App\Filament\Resources\CollectionRequests\Pages\EditCollectionRequest;
use App\Filament\Resources\CollectionRequests\Pages\ListCollectionRequests;
use App\Filament\Resources\CollectionRequests\Pages\ViewCollectionRequest;
use App\Filament\Resources\CollectionRequests\Schemas\CollectionRequestForm;
use App\Filament\Resources\CollectionRequests\Schemas\CollectionRequestInfolist;
use App\Filament\Resources\CollectionRequests\Tables\CollectionRequestsTable;
use App\Models\CollectionRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CollectionRequestResource extends Resource
{
    protected static ?string $model = CollectionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return CollectionRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CollectionRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CollectionRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCollectionRequests::route('/'),
            'create' => CreateCollectionRequest::route('/create'),
            'view' => ViewCollectionRequest::route('/{record}'),
            'edit' => EditCollectionRequest::route('/{record}/edit'),
        ];
    }
}
