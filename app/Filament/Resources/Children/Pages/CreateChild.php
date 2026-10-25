<?php

namespace App\Filament\Resources\Children\Pages;

use App\Concerns\SavesPersonPhoto;
use App\Filament\Resources\Children\ChildResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChild extends CreateRecord
{
    use SavesPersonPhoto;

    protected static string $resource = ChildResource::class;
}
