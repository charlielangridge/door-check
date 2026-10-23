<?php

namespace App\Filament\Resources\CollectionRequests\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CollectionRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uuid')->label('UUID')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('requested_at')->dateTime(),
                TextColumn::make('requester_name_text')->label('Requester Name'),
                TextColumn::make('requested_child_text')->label('Requested Child'),
                TextColumn::make('requester_latitude')->label('Requester Latitude'),
                TextColumn::make('requester_longitude')->label('Requester Longitude'),
                TextColumn::make('adult_id')->label('Adult ID'),
                TextColumn::make('requested_child_id')->label('Requested Child ID'),

            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
