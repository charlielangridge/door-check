<?php

use App\Models\CollectionRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\CollectionBrowserAccess;
use App\Services\StaffSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('staff.{siteId}', function (User $user, string $siteId): bool {
    $site = Site::query()->find($siteId);

    return $site !== null && $user->hasVerifiedEmail() && $user->can('processCollections', $site)
        && resolve(StaffSession::class)->isCurrent(request());
});

Broadcast::channel('collection.{uuid}', function (Authenticatable $browser, string $uuid): bool {
    $request = CollectionRequest::query()->where('uuid', $uuid)->first();

    return $request !== null && resolve(CollectionBrowserAccess::class)->allows(request(), $request);
}, ['guards' => ['collection-browser']]);
