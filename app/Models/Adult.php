<?php

namespace App\Models;

use Database\Factories\AdultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'name',
    'date_of_birth',
])]
class Adult extends Model
{
    /** @use HasFactory<AdultFactory> */
    use HasFactory;

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class, 'adult_child', 'adult_id', 'child_id')->withPivot('relationship');
    }
}
