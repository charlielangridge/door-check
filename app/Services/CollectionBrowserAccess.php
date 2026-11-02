<?php

namespace App\Services;

use App\Models\ChildAdultSite;
use App\Models\CollectionRequest;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;

class CollectionBrowserAccess
{
    public function allows(Request $request, CollectionRequest $collection): bool
    {
        $key = $request->cookie('collection_browser');

        return $collection->parent_request_id === null
            && is_string($key) && strlen($key) === 64
            && is_string($collection->browser_key_hash)
            && hash_equals($collection->browser_key_hash, hash('sha256', $key));
    }

    public function canAddChild(CollectionRequest $collection): bool
    {
        if ($collection->adult_id === null || $collection->groupIsFinished()) {
            return false;
        }

        return $collection->adult()->whereHas('sites', fn (Builder $query) => $query->whereKey($collection->site_id))->exists()
            && $collection->adult->children()->wherePivot('authorised_to_collect', true)->whereHas('sites', fn ($query) => $query->whereKey($collection->site_id)->where('site_child.is_active', true)->where('site_child.is_approved', true))
                ->whereNotIn('children.id', ChildAdultSite::query()->where('site_id', $collection->site_id)->where('adult_id', $collection->adult_id)->where('is_linked', false)->select('child_id'))->count() > 1;
    }
}
