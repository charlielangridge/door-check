<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SitesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('staffDashboard')->label('Staff dashboard')->icon('heroicon-o-rectangle-group')
                    ->visible(fn (Site $record): bool => auth()->user()->can('processCollections', $record))
                    ->url(fn (Site $record): string => route('staff.board', $record->uuid))->openUrlInNewTab(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
