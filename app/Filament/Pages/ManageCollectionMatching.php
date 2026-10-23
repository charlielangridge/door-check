<?php

namespace App\Filament\Pages;

use App\Settings\CollectionMatchingSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageCollectionMatching extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string $settings = CollectionMatchingSettings::class;

    protected static ?string $title = 'Collection matching settings';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('automatic_matching_threshold')
                    ->label('Automatic matching threshold')
                    ->helperText('JEV confidence required for automatic matching. Must be greater than 0 and at most 100. Applies to requests when matching first starts; existing requests keep their saved threshold.')
                    ->numeric()
                    ->required()
                    ->rules(['gt:0'])
                    ->maxValue(100)
                    ->suffix('%'),
            ]);
    }
}
