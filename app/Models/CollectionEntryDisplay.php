<?php

namespace App\Models;

use Database\Factories\CollectionEntryDisplayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['site_id'])]
class CollectionEntryDisplay extends Model
{
    /** @use HasFactory<CollectionEntryDisplayFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $display): void {
            $display->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<CollectionEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(CollectionEntry::class);
    }
}
