<?php

namespace App\Models;

use App\Services\CollectionAudit;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\Permission\PermissionRegistrar;

class AuditedAccessAssignment extends Pivot
{
    protected static function booted(): void
    {
        static::created(fn (self $pivot) => $pivot->recordAssignment('relationship_attached'));
        static::deleted(fn (self $pivot) => $pivot->recordAssignment('relationship_detached'));
    }

    private function recordAssignment(string $event): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($this->pivotParent !== null) {
            app(CollectionAudit::class)->record($event, $this->pivotParent, ['table' => $this->getTable(), ...$this->getAttributes()]);
        }
    }
}
