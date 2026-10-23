<?php

namespace App\Filament\Resources\Adults\RelationManagers;

use App\Filament\Resources\Sites\SiteResource;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class SitesRelationManager extends RelationManager
{
    protected static string $relationship = 'sites';

    protected static ?string $relatedResource = SiteResource::class;

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
