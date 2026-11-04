<?php

namespace Tests;

use App\Models\CollectionEntry;
use App\Models\CollectionEntryDisplay;
use App\Models\Site;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /** @return array{site: string, entry: string} */
    protected function openCollectionEntry(Site $site): array
    {
        $display = CollectionEntryDisplay::factory()->for($site)->create();
        $entry = CollectionEntry::factory()->for($display, 'display')->create();
        $parameters = ['site' => $site->slug, 'entry' => $entry->uuid];
        $response = $this->get(route('collection-request.create', $parameters))->assertOk();
        $this->withCredentials()->withCookie('collection_browser', $response->getCookie('collection_browser')->getValue());

        return $parameters;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
