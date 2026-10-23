<?php

namespace App\Filament\Resources\Adults\Pages;

use App\Filament\Resources\Adults\AdultResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdults extends ListRecords
{
    protected static string $resource = AdultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
