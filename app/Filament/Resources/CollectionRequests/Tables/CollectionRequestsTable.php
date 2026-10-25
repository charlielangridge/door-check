<?php

namespace App\Filament\Resources\CollectionRequests\Tables;

use App\Filament\Resources\CollectionRequests\CollectionRequestResource;
use App\Models\CollectionRequest;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CollectionRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['adult', 'requestedChild', 'site']))
            ->columns([
                TextColumn::make('uuid')->label('UUID')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('requested_at')->dateTime(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('collection_state')->label('Collection progress')->state(fn (CollectionRequest $record): string => $record->collection_state->label())->badge(),
                TextColumn::make('parent_request_id')->label('Parent request')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('site.name')->label('Site')->sortable(),
                TextColumn::make('requester_name_text')
                    ->label('Requester Name')
                    ->state(fn (CollectionRequest $record): ?string => $record->adult_id !== null ? $record->adult?->name : $record->requester_name_text),
                TextColumn::make('requested_child_text')
                    ->label('Requested Child')
                    ->state(fn (CollectionRequest $record): ?string => $record->requested_child_id !== null ? $record->requestedChild?->name : $record->requested_child_text),
                TextColumn::make('requester_latitude')->label('Requester Latitude')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('requester_longitude')->label('Requester Longitude')->toggleable(isToggledHiddenByDefault: true),

            ])
            ->filters([
                SelectFilter::make('site')->relationship('site', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                CollectionRequestResource::reopenAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
