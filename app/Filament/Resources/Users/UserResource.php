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
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Staff & users';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...static::accountFields(),
                Select::make('roles')->relationship('roles', 'name')->multiple()->preload()->searchable()->live()
                    ->helperText('Choose staff to give this account access to its assigned venues’ collection boards.'),
                Select::make('permissions')->relationship('permissions', 'name')->multiple()->preload()->searchable()->live()->helperText('Additional permissions; roles already include their assigned permissions.'),
                Select::make('staffSites')->relationship('staffSites', 'name')->multiple()->searchable()->preload()
                    ->label('Staff venues')->helperText('Staff can process collections at these venues. The process collections at any site permission grants access to every venue.')
                    ->required(function (Get $get): bool {
                        $roles = (array) $get('roles');
                        $global = Permission::query()->whereIn('id', (array) $get('permissions'))->where('name', 'process collections at any site')->exists()
                            || Role::query()->whereIn('id', $roles)->whereHas('permissions', fn ($query) => $query->where('name', 'process collections at any site'))->exists();

                        return ! $global && Role::query()->whereIn('id', $roles)->where('name', 'staff')->exists();
                    }),
            ]);
    }

    /** @return list<Component> */
    public static function accountFields(): array
    {
        return [
            TextInput::make('name')->label('Staff member name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
            TextInput::make('password')->password()->revealable()->required(fn (string $operation): bool => $operation === 'create')->minLength(12)->dehydrated(fn (?string $state): bool => filled($state)),
            DateTimePicker::make('email_verified_at')->label('Email verified at')->default(now()),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('roles.name')->badge(),
                TextColumn::make('staffSites.name')->label('Staff venues')->badge()->placeholder('Not assigned'),
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
