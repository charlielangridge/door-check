<?php

namespace App\Filament\Resources\Audits;

use App\Filament\Resources\Audits\Pages\ListAudits;
use App\Filament\Resources\Audits\Pages\ViewAudit;
use App\Models\Audit;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditResource extends Resource
{
    protected static ?string $model = Audit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('event'),
                TextEntry::make('actor_name')->label('Account')->placeholder('System / collecting adult'),
                TextEntry::make('auditable_type')->label('Record type'),
                TextEntry::make('auditable_id')->label('Record ID'),
                TextEntry::make('site_id')->label('Venue ID'),
                TextEntry::make('collection_request_id')->label('Request group ID'),
                TextEntry::make('created_at')->dateTime(timezone: config('staff-collections.timezone')),
                TextEntry::make('url')->wrap(),
                TextEntry::make('old_values')->state(fn (Audit $record): string => json_encode($record->old_values, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))->extraAttributes(['class' => 'whitespace-pre-wrap font-mono'])->columnSpanFull(),
                TextEntry::make('new_values')->state(fn (Audit $record): string => json_encode($record->new_values, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))->extraAttributes(['class' => 'whitespace-pre-wrap font-mono'])->columnSpanFull(),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable()->searchable(),
                TextColumn::make('event')->searchable()->badge(),
                TextColumn::make('actor_name')->label('Account')->placeholder('System / collecting adult')->searchable(),
                TextColumn::make('site_id')->label('Venue ID')->searchable(),
                TextColumn::make('collection_request_id')->label('Request ID')->searchable(),
                TextColumn::make('auditable_type')->label('Record type')->searchable(),
                TextColumn::make('created_at')->dateTime(timezone: config('staff-collections.timezone'))->sortable(),
            ])
            ->filters([
                SelectFilter::make('event')->options(fn (): array => Audit::query()->distinct()->orderBy('event')->pluck('event', 'event')->all()),
                Filter::make('collection_request_id')->schema([TextInput::make('value')->label('Request group ID')->numeric()])->query(fn (Builder $query, array $data): Builder => $query->when(filled($data['value'] ?? null), fn (Builder $query): Builder => $query->where('collection_request_id', $data['value']))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])->defaultSort('id', 'desc');
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
            'index' => ListAudits::route('/'),
            'view' => ViewAudit::route('/{record}'),
        ];
    }
}
