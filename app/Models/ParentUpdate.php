<?php

namespace App\Models;

use Database\Factories\ParentUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $original
 */
#[Fillable(['adult_access_id', 'site_id', 'adult_id', 'child_id', 'kind', 'status', 'payload', 'original', 'email_verified_at', 'reviewed_by', 'review_message'])]
class ParentUpdate extends Model
{
    /** @use HasFactory<ParentUpdateFactory> */
    use HasFactory;

    /** @return BelongsTo<AdultAccess, $this> */
    public function access(): BelongsTo
    {
        return $this->belongsTo(AdultAccess::class, 'adult_access_id');
    }

    protected $casts = ['payload' => 'array', 'original' => 'array', 'email_verified_at' => 'datetime'];
}
