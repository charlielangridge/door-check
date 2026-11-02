<?php

namespace App\Models;

use Database\Factories\ParentRegistrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** @property array{name?: string|null, also_known_as?: list<string>, photo_path?: string|null, adults?: list<array<string, mixed>>}|null $payload */
#[Fillable(['uuid', 'access_key', 'site_id', 'invited_by', 'adult_access_id', 'child_id', 'child_name', 'child_date_of_birth', 'email', 'status', 'failed_attempts', 'expires_at', 'submitted_at', 'approved_at', 'reviewed_by', 'correction_message', 'payload', 'revision'])]
class ParentRegistration extends Model
{
    /** @use HasFactory<ParentRegistrationFactory> */
    use HasFactory;

    protected $casts = ['child_date_of_birth' => 'date', 'expires_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'payload' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $registration): void {
            $registration->uuid ??= (string) Str::uuid();
            $registration->access_key ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function canEdit(): bool
    {
        return in_array($this->status, ['invited', 'draft', 'corrections'], true)
            && $this->failed_attempts < 5 && $this->expires_at->isFuture();
    }
}
