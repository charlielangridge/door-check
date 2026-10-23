<?php

namespace App\Filament\Resources\CollectionRequests\Pages;

use App\Filament\Resources\CollectionRequests\CollectionRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCollectionRequest extends EditRecord
{
    protected static string $resource = CollectionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
