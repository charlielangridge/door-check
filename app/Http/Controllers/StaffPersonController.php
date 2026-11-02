<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStaffPersonRequest;
use App\Models\Adult;
use App\Models\Child;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffPersonController extends Controller
{
    public function updateChild(UpdateStaffPersonRequest $request, Site $site, Child $child): RedirectResponse
    {
        DB::transaction(fn () => $this->savePerson($request, $child));

        return to_route('staff.children.index', $site->slug);
    }

    public function updateAdult(UpdateStaffPersonRequest $request, Site $site, Child $child, Adult $adult): RedirectResponse
    {
        DB::transaction(function () use ($request, $child, $adult): void {
            $this->savePerson($request, $adult);
            $child->adults()->updateExistingPivot($adult->id, ['relationship' => $request->validated('relationship')]);
        });

        return to_route('staff.children.index', $site->slug);
    }

    private function savePerson(UpdateStaffPersonRequest $request, Adult|Child $person): void
    {
        $person->update($request->safe()->only(['name', 'date_of_birth', 'also_known_as']));
        if ($request->hasFile('new_photo')) {
            $photo = $request->file('new_photo');
            $person->addMedia($photo)->usingFileName(Str::uuid().'.'.$photo->guessExtension())->toMediaCollection('logo', 'local');
        }
    }
}
