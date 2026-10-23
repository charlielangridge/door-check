<?php

use App\Models\CollectionRequest;

test('collection requests can be paginated using the migrated table', function (): void {
    $requests = CollectionRequest::query()->paginate();

    expect($requests->total())->toBe(0);
});
