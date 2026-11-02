<?php

namespace App\Filament\Resources\Adults\RelationManagers;

use App\Filament\Resources\Children\ChildResource;
use App\Models\Adult;
use App\Models\Child;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChildrenRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    protected static ?string $relatedResource = ChildResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->pushColumns([
                TextColumn::make('relationship'),
                IconColumn::make('authorised_to_collect')->label('Authorised to collect')->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
                AttachAction::make()
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('relationship')->required(),
                        Toggle::make('authorised_to_collect')->label('Authorised to collect')->default(true),
                    ]),
            ])
            ->pushRecordActions([
                Action::make('collectionPermission')->label('Collection permission')
                    ->visible(fn (): bool => auth()->user()?->can('access admin') ?? false)
                    ->fillForm(fn (Child $record): array => ['authorised_to_collect' => $record->getRelation('pivot')->getAttribute('authorised_to_collect')])
                    ->schema([Toggle::make('authorised_to_collect')->label('Authorised to collect')->default(true)])
                    ->action(function (array $data, Child $record): void {
                        abort_unless(auth()->user()?->can('access admin'), 403);
                        $owner = $this->getOwnerRecord();
                        abort_unless($owner instanceof Adult && $owner->children()->whereKey($record->id)->exists(), 404);
                        $owner->children()->updateExistingPivot($record->id, ['authorised_to_collect' => $data['authorised_to_collect']]);
                    }),
                DetachAction::make(),
            ]);
    }
}
