<?php

namespace App\Filament\Resources\Children\RelationManagers;

use App\Filament\Resources\Adults\AdultResource;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdultsRelationManager extends RelationManager
{
    protected static string $relationship = 'adults';

    protected static ?string $relatedResource = AdultResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->pushColumns([
                TextColumn::make('relationship'),
            ])
            ->headerActions([
                CreateAction::make(),
                AttachAction::make()
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('relationship')->required(),
                    ]),
            ])
            ->pushRecordActions([
                DetachAction::make(),
            ]);
    }
}
