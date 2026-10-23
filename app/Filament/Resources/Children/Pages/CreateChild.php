<?php

namespace App\Filament\Resources\Children\Pages;

use App\Filament\Resources\Children\ChildResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChild extends CreateRecord
{
    protected static string $resource = ChildResource::class;
}
