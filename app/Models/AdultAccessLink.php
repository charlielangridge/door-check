<?php

namespace App\Models;

use Database\Factories\AdultAccessLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'adult_access_id', 'site_id', 'purpose', 'email', 'expires_at', 'used_at', 'revoked_at', 'failed_attempts'])]
class AdultAccessLink extends Model
{
    /** @use HasFactory<AdultAccessLinkFactory> */
    use HasFactory;

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $link): void {
            $link->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<AdultAccess, $this> */
    public function access(): BelongsTo
    {
        return $this->belongsTo(AdultAccess::class, 'adult_access_id');
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->revoked_at === null
            && $this->failed_attempts < 5 && $this->expires_at->isFuture();
    }
}
