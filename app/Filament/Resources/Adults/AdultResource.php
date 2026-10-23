<?php

namespace App\Filament\Resources\Adults;

use App\Filament\Resources\Adults\Pages\CreateAdult;
use App\Filament\Resources\Adults\Pages\EditAdult;
use App\Filament\Resources\Adults\Pages\ListAdults;
use App\Filament\Resources\Adults\RelationManagers\ChildrenRelationManager;
use App\Filament\Resources\Adults\RelationManagers\SitesRelationManager;
use App\Filament\Resources\Adults\Schemas\AdultForm;
use App\Filament\Resources\Adults\Tables\AdultsTable;
use App\Models\Adult;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AdultResource extends Resource
{
    protected static ?string $model = Adult::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AdultForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdultsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChildrenRelationManager::class,
            SitesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdults::route('/'),
            'create' => CreateAdult::route('/create'),
            'edit' => EditAdult::route('/{record}/edit'),
        ];
    }
}
