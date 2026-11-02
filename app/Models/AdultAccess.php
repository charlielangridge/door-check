<?php

namespace App\Models;

use Database\Factories\AdultAccessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'adult_id', 'email', 'name', 'date_of_birth', 'session_version'])]
class AdultAccess extends Model
{
    /** @use HasFactory<AdultAccessFactory> */
    use HasFactory;

    protected $attributes = ['session_version' => 1];

    protected $casts = ['date_of_birth' => 'date'];

    protected static function booted(): void
    {
        static::creating(function (self $access): void {
            $access->uuid ??= (string) Str::uuid();
            $access->email = mb_strtolower(trim($access->email));
        });
    }

    /** @return BelongsTo<Adult, $this> */
    public function adult(): BelongsTo
    {
        return $this->belongsTo(Adult::class);
    }

    public function identityDateOfBirth(): string
    {
        return $this->adult?->date_of_birth->toDateString() ?? $this->date_of_birth->toDateString();
    }
}
