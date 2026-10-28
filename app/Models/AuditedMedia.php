<?php

namespace App\Models;

use App\Services\CollectionRecordChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Auditable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AuditedMedia extends Media implements \OwenIt\Auditing\Contracts\Auditable
{
    use Auditable;
    use HasFactory;

    protected static function booted(): void
    {
        $invalidate = function (self $media): void {
            $person = $media->model;
            if ($person !== null) {
                DB::afterCommit(fn () => resolve(CollectionRecordChanges::class)->changed($person));
            }
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function transformAudit(array $data): array
    {
        if ($this->model_type === CollectionRequest::class) {
            $request = CollectionRequest::query()->find($this->model_id);
            $data['collection_request_id'] = $request?->groupId();
            $data['site_id'] = $request?->site_id;
        } elseif ($this->model_type === Adult::class) {
            $data['adult_id'] = $this->model_id;
        } elseif ($this->model_type === Child::class) {
            $data['child_id'] = $this->model_id;
        }

        return $data;
    }
}
