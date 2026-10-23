<?php

namespace App\Models;

use App\CollectionRequestStatus;
use App\Observers\CollectionRequestObserver;
use Database\Factories\CollectionRequestFactory;
use Dyrynda\Database\Support\BindsOnUuid;
use Dyrynda\Database\Support\GeneratesUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid',
    'requested_at',
    'requester_name_text',
    'requested_child_text',
    'requester_latitude',
    'requester_longitude',
    'adult_id',
    'requested_child_id',
])]
#[Table('collection_request')]
#[ObservedBy([CollectionRequestObserver::class])]
class CollectionRequest extends Model
{
    use BindsOnUuid, GeneratesUuid;

    /** @use HasFactory<CollectionRequestFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
        'adult_match_status' => 'pending',
        'child_match_status' => 'pending',
    ];

    protected $casts = [
        'status' => CollectionRequestStatus::class,
        'adult_match_status' => CollectionRequestStatus::class,
        'child_match_status' => CollectionRequestStatus::class,
        'adult_match_confidence' => 'float',
        'child_match_confidence' => 'float',
        'matching_threshold' => 'float',
        'adult_match_candidates' => 'array',
        'child_match_candidates' => 'array',
        'adult_candidates_truncated' => 'boolean',
        'child_candidates_truncated' => 'boolean',
        'requested_at' => 'datetime',
        'requester_latitude' => 'decimal:8',
        'requester_longitude' => 'decimal:8',
    ];

    /** @return BelongsTo<Adult, $this> */
    public function adult(): BelongsTo
    {
        return $this->belongsTo(Adult::class);
    }

    /** @return BelongsTo<Child, $this> */
    public function requestedChild(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'requested_child_id');
    }
}
