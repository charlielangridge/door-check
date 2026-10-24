<?php

namespace App\Models;

use Database\Factories\AdultFactory;
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
class Adult extends Model implements HasMedia
{
    /** @use HasFactory<AdultFactory> */
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
     * @return BelongsToMany<Child, $this, Pivot>
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class, 'adult_child', 'adult_id', 'child_id')->withPivot('relationship');
    }

    /** @return BelongsToMany<Site, $this> */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_adult', 'adult_id', 'site_id');
    }
}
