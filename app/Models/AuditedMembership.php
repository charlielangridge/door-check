<?php

namespace App\Models;

use App\Services\CollectionAudit;
use App\Services\CollectionRecordChanges;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;

class AuditedMembership extends Pivot
{
    protected $casts = ['authorised_to_collect' => 'boolean', 'is_active' => 'boolean', 'is_approved' => 'boolean', 'access_suspended' => 'boolean'];

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(fn (self $pivot) => $pivot->recordActivity('relationship_attached'));
        static::updated(fn (self $pivot) => $pivot->recordActivity('relationship_changed'));
        static::deleted(fn (self $pivot) => $pivot->recordActivity('relationship_detached'));
    }

    private function recordActivity(string $event): void
    {
        if ($event === 'relationship_detached' && $this->getTable() === 'adult_child') {
            ChildAdultSite::query()->where('adult_id', $this->getAttribute('adult_id'))->where('child_id', $this->getAttribute('child_id'))->delete();
        }
        if ($this->pivotParent !== null) {
            resolve(CollectionAudit::class)->record($event, $this->pivotParent, ['table' => $this->getTable(), ...$this->getAttributes()]);
            DB::afterCommit(fn () => resolve(CollectionRecordChanges::class)->changed($this->pivotParent));
        }
    }
}
