<?php

namespace App\Filament\Resources\Children\Pages;

use App\Concerns\SavesPersonPhoto;
use App\Filament\Resources\Children\ChildResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditChild extends EditRecord
{
    use SavesPersonPhoto;

    protected static string $resource = ChildResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
