<?php

use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\CollectionBrowserAccess;
use App\Services\StaffSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('staff.{siteId}', function (User $user, string $siteId): bool {
    return $user->hasVerifiedEmail() && Site::query()->whereKey($siteId)->exists()
        && ($user->can('access admin') || ($user->can('access staff') && (int) $user->staff_site_id === (int) $siteId
            && app(StaffSession::class)->isCurrent(request())));
});

Broadcast::channel('collection.{uuid}', function (Authenticatable $browser, string $uuid): bool {
    $request = CollectionRequest::query()->where('uuid', $uuid)->first();

    return $request !== null && app(CollectionBrowserAccess::class)->allows(request(), $request);
}, ['guards' => ['collection-browser']]);
