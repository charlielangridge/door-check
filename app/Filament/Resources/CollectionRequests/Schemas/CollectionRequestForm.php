<?php

namespace App\Filament\Resources\CollectionRequests\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CollectionRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DateTimePicker::make('requested_at')->required(),
                TextInput::make('requester_name_text')->label('Requester Name')->required(),
                TextInput::make('requested_child_text')->label('Requested Child')->required(),
            ]);
    }
}
