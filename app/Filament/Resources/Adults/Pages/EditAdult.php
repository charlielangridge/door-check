<?php

namespace App\Filament\Resources\Adults\Pages;

use App\Concerns\SavesPersonPhoto;
use App\Filament\Resources\Adults\AdultResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAdult extends EditRecord
{
    use SavesPersonPhoto;

    protected static string $resource = AdultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
