<?php

namespace App\Filament\Resources\Adults\Pages;

use App\Concerns\SavesPersonPhoto;
use App\Filament\Resources\Adults\AdultResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdult extends CreateRecord
{
    use SavesPersonPhoto;

    protected static string $resource = AdultResource::class;
}
