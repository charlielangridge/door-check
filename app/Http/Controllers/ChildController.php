<?php

namespace App\Http\Controllers;

use App\Models\Adult;
use App\Models\Child;
use App\Models\Site;
use Inertia\Inertia;
use Inertia\Response;

class ChildController extends Controller
{
    public function index(Site $site): Response
    {
        $site->load('children.media', 'children.adults.media');

        return Inertia::render('Staff/Child/Index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'slug' => $site->slug,
                'logo' => $site->getFirstMediaUrl('logo', 'medium'),
            ],
            'registrationDuplicates' => session('registration_duplicates', ['child' => null, 'adults' => []]),
            'registeredChildUuid' => session('registered_child_uuid'),
            'children' => $site->children->sortBy('last_name')->sortBy('first_name')->values()->map(function (Child $child) use ($site): array {
                return [
                    'name' => $child->name,
                    'photo' => $this->photo($child, $site),
                    'uuid' => $child->uuid,
                    'date_of_birth' => $child->date_of_birth->toDateString(),
                    'age' => $child->date_of_birth->age,
                    'also_known_as' => $child->also_known_as ?? [],
                    'adults' => $child->adults->map(fn (Adult $adult): array => [
                        'id' => $adult->id,
                        'name' => $adult->name,
                        'date_of_birth' => $adult->date_of_birth->toDateString(),
                        'also_known_as' => $adult->also_known_as ?? [],
                        'photo' => $this->photo($adult, $site),
                        'relationship' => $adult->getRelation('pivot')->getAttribute('relationship'),
                    ])->values(),
                ];
            }),
        ]);
    }

    private function photo(Adult|Child $person, Site $site): ?string
    {
        $media = $person->getMedia('*')->whereIn('collection_name', ['photo', 'logo'])->sortBy('id')->last();

        return $media ? route('staff.media.show', ['site' => $site->slug, 'media' => $media->getKey()]) : null;
    }
}
