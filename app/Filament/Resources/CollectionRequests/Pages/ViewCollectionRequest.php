<?php

namespace App\Filament\Resources\CollectionRequests\Pages;

use App\Filament\Resources\Audits\AuditResource;
use App\Filament\Resources\CollectionRequests\CollectionRequestResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCollectionRequest extends ViewRecord
{
    protected static string $resource = CollectionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CollectionRequestResource::reopenAction(),
            Action::make('auditTrail')->label('View audit trail')->url(fn (): string => AuditResource::getUrl('index', ['tableFilters' => ['collection_request_id' => ['value' => $this->getRecord()->getAttribute('parent_request_id') ?? $this->getRecord()->getKey()]]])),
            EditAction::make(),
        ];
    }
}
