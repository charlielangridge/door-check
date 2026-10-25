<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CollectionAudit
{
    /** @param array<string, mixed> $details */
    public function record(string $event, Model $subject, array $details = [], ?User $actor = null): Audit
    {
        $actor ??= auth()->user();

        return Audit::query()->create([
            'event' => $event,
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            'user_type' => $actor?->getMorphClass(),
            'user_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'site_id' => ($details['table'] ?? null) === 'adult_child' ? null : ($subject instanceof CollectionRequest ? $subject->site_id : ($details['site_id'] ?? ($subject instanceof Site ? $subject->getKey() : $actor?->staff_site_id))),
            'collection_request_id' => $subject instanceof CollectionRequest ? $subject->groupId() : null,
            'adult_id' => $details['adult_id'] ?? null,
            'child_id' => $details['child_id'] ?? null,
            'old_values' => [],
            'new_values' => $details,
            'url' => app()->runningInConsole() ? null : request()->url(),
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : mb_substr((string) request()->userAgent(), 0, 1023),
        ]);
    }
}
