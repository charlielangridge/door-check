<?php

namespace App\Models;

use Database\Factories\ChildFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable([
    'name',
    'date_of_birth',
])]
class Child extends Model implements HasMedia
{
    /** @use HasFactory<ChildFactory> */
    use HasFactory;

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

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    /**
     * @return BelongsToMany<Adult, $this, Pivot>
     */
    public function adults(): BelongsToMany
    {
        return $this->belongsToMany(Adult::class, 'adult_child', 'child_id', 'adult_id')
            ->withPivot('relationship');
    }

    /** @return BelongsToMany<Site, $this> */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_child', 'child_id', 'site_id');
    }
}
