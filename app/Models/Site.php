<?php

namespace App\Models;

use Database\Factories\SiteFactory;
use Dyrynda\Database\Support\BindsOnUuid;
use Dyrynda\Database\Support\GeneratesUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'uuid',
])]
class Site extends Model
{
    use BindsOnUuid, GeneratesUuid;

    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    /** @return BelongsToMany<Adult, $this> */
    public function adults(): BelongsToMany
    {
        return $this->belongsToMany(Adult::class, 'site_adult', 'site_id', 'adult_id');
    }

    /** @return BelongsToMany<Child, $this> */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class, 'site_child', 'site_id', 'child_id');
    }

    /** @return HasMany<CollectionRequest, $this> */
    public function collectionRequests(): HasMany
    {
        return $this->hasMany(CollectionRequest::class);
    }

    protected function casts(): array
    {
        return [
            'uuid' => 'string',
        ];
    }
}
