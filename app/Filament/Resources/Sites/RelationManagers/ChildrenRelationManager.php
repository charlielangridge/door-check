<?php

namespace App\Filament\Resources\Sites\RelationManagers;

use App\Filament\Resources\Children\ChildResource;
use App\Models\Child;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ChildrenRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    protected static ?string $relatedResource = ChildResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->pushColumns([IconColumn::make('is_active')->label('Active at this nursery')->boolean()])
            ->filters([TernaryFilter::make('is_active')->label('Active at this nursery')->queries(
                true: fn ($query) => $query->where('site_child.is_active', true),
                false: fn ($query) => $query->where('site_child.is_active', false),
                blank: fn ($query) => $query,
            )])
            ->headerActions([
                CreateAction::make(),
                AttachAction::make(),
            ])
            ->pushRecordActions([
                Action::make('attendance')->label('Attendance')
                    ->visible(fn (): bool => auth()->user()?->can('access admin') ?? false)
                    ->fillForm(fn (Child $record): array => ['is_active' => $record->getRelation('pivot')->getAttribute('is_active')])
                    ->schema([Toggle::make('is_active')->label('Active at this nursery')])
                    ->action(function (array $data, Child $record): void {
                        abort_unless(auth()->user()?->can('access admin'), 403);
                        $owner = $this->getOwnerRecord();
                        abort_unless($owner instanceof Site && $owner->children()->whereKey($record->id)->exists(), 404);
                        $owner->children()->updateExistingPivot($record->id, ['is_active' => $data['is_active']]);
                    }),
                DetachAction::make(),
            ]);
    }
}
