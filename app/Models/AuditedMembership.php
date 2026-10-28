<?php

namespace App\Models;

use App\Services\CollectionAudit;
use App\Services\CollectionRecordChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

class AuditedMembership extends Pivot
{
    use HasFactory;

    protected static function booted(): void
    {
        static::created(fn (self $pivot) => $pivot->recordActivity('relationship_attached'));
        static::updated(fn (self $pivot) => $pivot->recordActivity('relationship_changed'));
        static::deleted(fn (self $pivot) => $pivot->recordActivity('relationship_detached'));
    }

    private function recordActivity(string $event): void
    {
        if ($this->pivotParent !== null) {
            resolve(CollectionAudit::class)->record($event, $this->pivotParent, ['table' => $this->getTable(), ...$this->getAttributes()]);
            DB::afterCommit(fn () => resolve(CollectionRecordChanges::class)->changed($this->pivotParent));
        }
    }
}
