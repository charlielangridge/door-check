<?php

namespace App\Filament\Resources\CollectionRequests\Pages;

use App\Filament\Resources\CollectionRequests\CollectionRequestResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCollectionRequest extends ViewRecord
{
    protected static string $resource = CollectionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
