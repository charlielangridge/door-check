<?php

namespace App\Filament\Resources\Sites\RelationManagers;

use App\Filament\Resources\Adults\AdultResource;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class AdultsRelationManager extends RelationManager
{
    protected static string $relationship = 'adults';

    protected static ?string $relatedResource = AdultResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make(),
                AttachAction::make(),
            ])
            ->pushRecordActions([
                DetachAction::make(),
            ]);
    }
}
