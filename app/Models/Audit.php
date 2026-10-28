<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Models\Audit as BaseAudit;

class Audit extends BaseAudit
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $audit): void {
            if ($audit->user_id !== null && $audit->actor_name === null) {
                $audit->actor_name = User::query()->whereKey($audit->user_id)->value('name')
                    ?? (auth()->id() === $audit->user_id ? auth()->user()?->name : null);
            }
            foreach (['old_values', 'new_values'] as $side) {
                $values = $audit->getAttribute($side) ?? [];
                foreach (['also_known_as', 'adult_match_candidates', 'child_match_candidates', 'unavailable_photos'] as $field) {
                    if (isset($values[$field]) && is_string($values[$field])) {
                        $values[$field] = json_decode($values[$field], true, flags: JSON_THROW_ON_ERROR);
                    }
                }
                $audit->setAttribute($side, $values);
            }
        });
    }
}
