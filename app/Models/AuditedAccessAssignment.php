<?php

namespace App\Models;

use App\Services\CollectionAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\Permission\PermissionRegistrar;

class AuditedAccessAssignment extends Pivot
{
    use HasFactory;

    protected static function booted(): void
    {
        static::created(fn (self $pivot) => $pivot->recordAssignment('relationship_attached'));
        static::deleted(fn (self $pivot) => $pivot->recordAssignment('relationship_detached'));
    }

    private function recordAssignment(string $event): void
    {
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($this->pivotParent !== null) {
            resolve(CollectionAudit::class)->record($event, $this->pivotParent, ['table' => $this->getTable(), ...$this->getAttributes()]);
        }
    }
}
