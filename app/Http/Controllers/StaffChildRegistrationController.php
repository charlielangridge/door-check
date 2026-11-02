<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStaffChildRequest;
use App\Models\Adult;
use App\Models\Child;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class StaffChildRegistrationController extends Controller
{
    public function adults(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:255']]);
        $adults = $site->adults()->with('media')->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($data['q']).'%'])
            ->orderBy('name')->orderBy('adults.id')->limit(15)->get();

        return response()->json(['adults' => $adults->map(fn (Adult $adult): array => $this->summary($adult, $site))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreStaffChildRequest $request, Site $site, Filesystem $files): RedirectResponse
    {
        $media = [];
        try {
            $child = DB::transaction(function () use ($request, $site, &$media): Child {
                $site->newQuery()->whereKey($site->id)->lockForUpdate()->firstOrFail();
                $data = $request->validated();
                $this->checkDuplicates($request, $site, $data);
                $child = $this->createPerson(new Child, [
                    'name' => $data['name'],
                    'date_of_birth' => $data['date_of_birth'],
                    'also_known_as' => $data['also_known_as'] ?? [],
                    'new_photo' => $data['new_photo'] ?? null,
                ], $media);
                $child->sites()->attach($site);
                foreach ($data['adults'] as $adultData) {
                    if ($adultData['mode'] === 'existing') {
                        $adult = $site->adults()->whereKey($adultData['adult_id'])->firstOrFail();
                    } else {
                        $adult = $this->createPerson(new Adult, $adultData, $media);
                        $adult->sites()->attach($site);
                    }
                    $child->adults()->attach($adult, ['relationship' => $adultData['relationship']]);
                }

                return $child;
            });
        } catch (Throwable $exception) {
            foreach ($media as $photo) {
                $files->removeAllFiles($photo);
            }
            throw $exception;
        }

        return to_route('staff.children.index', $site->slug)->with('registered_child_uuid', $child->uuid);
    }

    /**
     * @template T of Adult|Child
     *
     * @param  T  $person
     * @param  array{name: string, date_of_birth: string, also_known_as?: list<string>, new_photo?: UploadedFile|null}  $data
     * @param  list<Media>  $media
     * @return T
     */
    private function createPerson(Adult|Child $person, array $data, array &$media): Adult|Child
    {
        $person->fill(['name' => $data['name'], 'date_of_birth' => $data['date_of_birth'], 'also_known_as' => $data['also_known_as'] ?? []])->save();
        if (($data['new_photo'] ?? null) instanceof UploadedFile) {
            $photo = $data['new_photo'];
            $media[] = $person->addMedia($photo)->usingFileName(Str::uuid().'.'.$photo->guessExtension())->toMediaCollection('logo', 'local');
        }

        return $person;
    }

    /** @param array<string, mixed> $data */
    private function checkDuplicates(StoreStaffChildRequest $request, Site $site, array $data): void
    {
        $duplicates = ['child' => null, 'adults' => []];
        $errors = [];
        $children = Child::query()->whereHas('sites', fn (Builder $query): Builder => $query->whereKey($site->id))
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($data['name']))])->whereDate('date_of_birth', $data['date_of_birth'])->with('media')->get();
        if ($children->isNotEmpty() && ! ($data['create_different'] ?? false)) {
            $duplicates['child'] = ['name' => $data['name'], 'date_of_birth' => $data['date_of_birth'], 'people' => $children->map(fn (Child $child): array => $this->summary($child, $site))->all()];
            $errors['create_different'] = 'A child with these details already exists. Open their record or confirm this is a different child.';
        }
        foreach ($data['adults'] as $index => $adultData) {
            if ($adultData['mode'] !== 'new' || ($adultData['create_different'] ?? false)) {
                continue;
            }
            $adults = $site->adults()->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($adultData['name']))])
                ->whereDate('date_of_birth', $adultData['date_of_birth'])->with('media')->get();
            if ($adults->isNotEmpty()) {
                $duplicates['adults'][$index] = ['name' => $adultData['name'], 'date_of_birth' => $adultData['date_of_birth'], 'people' => $adults->map(fn (Adult $adult): array => $this->summary($adult, $site))->all()];
                $errors['adults.'.$index.'.create_different'] = 'An adult with these details already exists. Link them or confirm this is a different person.';
            }
        }
        if ($errors !== []) {
            $request->session()->flash('registration_duplicates', $duplicates);
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array{id: int, uuid: string|null, name: string, date_of_birth: string, photo: string|null} */
    private function summary(Adult|Child $person, Site $site): array
    {
        $photo = $person->getMedia('*')->whereIn('collection_name', ['photo', 'logo'])->sortBy('id')->last();

        return ['id' => $person->id, 'uuid' => $person instanceof Child ? $person->uuid : null, 'name' => $person->name,
            'date_of_birth' => $person->date_of_birth->toDateString(), 'photo' => $photo ? route('staff.media.show', ['site' => $site->slug, 'media' => $photo->id]) : null];
    }
}
