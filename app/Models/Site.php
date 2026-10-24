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
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable([
    'name',
    'uuid',
])]
class Site extends Model implements HasMedia
{
    use BindsOnUuid, GeneratesUuid;
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->registerMediaConversions(function (?Media $media = null): void {

                $this->addMediaConversion('large')
                    ->fit(Fit::Fill, 1000, 1000);
                $this->addMediaConversion('medium')
                    ->fit(Fit::Fill, 500, 500);
                $this->addMediaConversion('small')->fit(Fit::Fill, 250, 250);
                $this->addMediaConversion('thumb')->fit(Fit::Fill, 100, 100);

            });

    }

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
