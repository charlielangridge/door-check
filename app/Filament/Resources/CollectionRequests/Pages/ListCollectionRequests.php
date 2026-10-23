<?php

namespace App\Filament\Resources\CollectionRequests\Pages;

use App\Filament\Resources\CollectionRequests\CollectionRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCollectionRequests extends ListRecords
{
    protected static string $resource = CollectionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
