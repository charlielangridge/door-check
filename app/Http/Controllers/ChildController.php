<?php

namespace App\Http\Controllers;

use App\Models\Adult;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChildController extends Controller
{
    public function index(Request $request, Site $site): Response
    {
        $inactive = $request->boolean('inactive');
        $site->load(['children' => fn ($query) => $query->where('site_child.is_approved', true)->where('site_child.is_active', ! $inactive)->with('media', 'adults.media')]);
        $excluded = ChildAdultSite::query()->where('site_id', $site->id)->where('is_linked', false)->get()->groupBy('child_id');

        return Inertia::render('Staff/Child/Index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'slug' => $site->slug,
                'logo' => $site->getFirstMediaUrl('logo', 'medium'),
            ],
            'registrationDuplicates' => session('registration_duplicates', ['child' => null, 'adults' => []]),
            'registeredChildUuid' => session('registered_child_uuid'),
            'inactive' => $inactive,
            'children' => $site->children->sortBy('last_name')->sortBy('first_name')->values()->map(function (Child $child) use ($site, $excluded): array {
                return [
                    'name' => $child->name,
                    'photo' => $this->photo($child, $site),
                    'uuid' => $child->uuid,
                    'date_of_birth' => $child->date_of_birth->toDateString(),
                    'age' => $child->date_of_birth->age,
                    'also_known_as' => $child->also_known_as ?? [],
                    'adults' => $child->adults->whereNotIn('id', $excluded->get($child->id, collect())->pluck('adult_id')->all())->map(fn (Adult $adult): array => [
                        'id' => $adult->id,
                        'name' => $adult->name,
                        'email' => $adult->email,
                        'phone' => $adult->phone,
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
