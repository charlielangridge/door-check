<?php

namespace App\Filament\Resources\Adults\Schemas;

use App\Models\Adult;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Schema;

class AdultForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TagsInput::make('also_known_as')->label('Also known as')->helperText('Add alternative full names, pressing Enter after each.')->nestedRecursiveRules(['string', 'max:255'])->rules(['array', 'max:50']),
                FileUpload::make('new_photo')->label('Add a stored photo')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048)->storeFiles(false)->dehydrated(false)->helperText('The latest saved photo is used for collection checks. Older photos remain stored.'),
                ImageEntry::make('stored_photo')->label('Current stored photo')->imageHeight(160)
                    ->state(fn (?Adult $record): ?string => $record?->getMedia('photo')->last() ? route('admin.media.show', $record->getMedia('photo')->last()->getKey()) : null)
                    ->visible(fn (?Adult $record): bool => $record !== null && $record->getMedia('photo')->isNotEmpty()),
                DatePicker::make('date_of_birth')
                    ->required(),
            ]);
    }
}
