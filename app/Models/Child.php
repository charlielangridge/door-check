<?php

namespace App\Models;

use Database\Factories\ChildFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'name',
    'date_of_birth',
])]
class Child extends Model
{
    /** @use HasFactory<ChildFactory> */
    use HasFactory;

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    public function adults(): BelongsToMany
    {
        return $this->belongsToMany(Adult::class, 'adult_child', 'child_id', 'adult_id')
            ->withPivot('relationship');
    }
}
