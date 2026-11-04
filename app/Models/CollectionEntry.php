<?php

namespace App\Models;

use Database\Factories\CollectionEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['collection_entry_display_id', 'token_expires_at'])]
class CollectionEntry extends Model
{
    /** @use HasFactory<CollectionEntryFactory> */
    use HasFactory;

    protected $hidden = ['browser_key_hash'];

    protected $casts = ['token_expires_at' => 'datetime', 'claimed_at' => 'datetime', 'form_expires_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<CollectionEntryDisplay, $this> */
    public function display(): BelongsTo
    {
        return $this->belongsTo(CollectionEntryDisplay::class, 'collection_entry_display_id');
    }

    /** @return BelongsTo<CollectionRequest, $this> */
    public function collectionRequest(): BelongsTo
    {
        return $this->belongsTo(CollectionRequest::class);
    }
}
