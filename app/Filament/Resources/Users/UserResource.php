<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('Staff member name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
                TextInput::make('password')->password()->revealable()->required(fn (string $operation): bool => $operation === 'create')->minLength(12)->dehydrated(fn (?string $state): bool => filled($state)),
                DateTimePicker::make('email_verified_at')->label('Email verified at')->default(now()),
                Select::make('roles')->relationship('roles', 'name')->multiple()->preload()->searchable(),
                Select::make('permissions')->relationship('permissions', 'name')->multiple()->preload()->searchable()->helperText('Additional permissions; roles already include their assigned permissions.'),
                Select::make('staff_site_id')->relationship('staffSite', 'name')->searchable()->preload()
                    ->label('Staff venue')->helperText('The kiosk can only access this venue.')
                    ->required(fn (Get $get): bool => Role::query()->whereIn('id', (array) $get('roles'))->where('name', 'staff')->exists()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('roles.name')->badge(),
                TextColumn::make('staffSite.name')->label('Staff venue')->placeholder('Not assigned'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
