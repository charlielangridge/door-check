<?php

namespace App\Models;

use Database\Factories\AdultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Auditable;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** @property list<string>|null $also_known_as */
#[Fillable([
    'name',
    'date_of_birth',
    'also_known_as',
])]
class Adult extends Model implements \OwenIt\Auditing\Contracts\Auditable, HasMedia
{
    use Auditable;

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
        'also_known_as' => 'array',
        'date_of_birth' => 'date',
    ];

    /**
     * @return BelongsToMany<Child, $this, AuditedMembership>
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class, 'adult_child', 'adult_id', 'child_id')->using(AuditedMembership::class)->withPivot('relationship');
    }

    /** @return BelongsToMany<Site, $this, AuditedMembership> */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_adult', 'adult_id', 'site_id')->using(AuditedMembership::class);
    }
}
