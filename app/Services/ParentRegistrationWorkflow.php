<?php

namespace App\Services;

use App\Http\Requests\SaveParentRegistrationRequest;
use App\Mail\ParentActionMail;
use App\Models\Adult;
use App\Models\AdultAccess;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\ParentRegistration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ParentRegistrationWorkflow
{
    public function __construct(
        private readonly ParentDocuments $documents,
        private readonly AdultPortalAccess $portal,
        private readonly CollectionAudit $audit,
        private readonly Filesystem $mediaFiles,
    ) {}

    /** @param array<string, mixed> $data */
    public function invite(Site $site, User $staff, array $data): ParentRegistration
    {
        return DB::transaction(function () use ($site, $staff, $data): ParentRegistration {
            $registration = ParentRegistration::query()->create([
                'site_id' => $site->id, 'invited_by' => $staff->id, 'child_name' => $data['name'],
                'child_date_of_birth' => $data['date_of_birth'], 'email' => mb_strtolower(trim($data['email'])),
                'expires_at' => now()->addDays(7),
            ]);
            $this->send($registration);
            $this->audit->record('parent_invited', $registration, ['site_id' => $site->id], $staff);

            return $registration;
        });
    }

    public function url(ParentRegistration $registration): string
    {
        return URL::temporarySignedRoute('parent-registration.show', $registration->expires_at, ['registration' => $registration->uuid, 'key' => $registration->access_key]);
    }

    private function send(ParentRegistration $registration): void
    {
        Mail::to($registration->email)->send(new ParentActionMail(
            'Register your child with '.$registration->site->name,
            $registration->correction_message
                ? 'The nursery has returned your registration for corrections: '.$registration->correction_message
                : 'Complete your child registration within seven days. You will need your child’s date of birth. The link closes once you submit.',
            $this->url($registration), 'Complete registration',
        ));
    }

    public function resend(ParentRegistration $registration, User $staff, ?string $email = null, ?string $dob = null): void
    {
        DB::transaction(function () use ($registration, $staff, $email, $dob): void {
            $registration = ParentRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            abort_unless(in_array($registration->status, ['invited', 'draft', 'corrections'], true), 409, 'Submitted registrations cannot be reopened by resending.');
            $email = mb_strtolower(trim($email ?? $registration->email));
            $data = ['access_key' => (string) Str::uuid(), 'expires_at' => now()->addDays(7), 'failed_attempts' => 0];
            if ($email !== $registration->email) {
                $data += ['email' => $email, 'payload' => null, 'adult_access_id' => null, 'status' => 'invited', 'correction_message' => null];
            }
            if ($dob !== null) {
                $data['child_date_of_birth'] = $dob;
            }
            $registration->update([...$data, 'revision' => $registration->revision + 1]);
            $this->send($registration);
            $this->audit->record('parent_invitation_resent', $registration, ['site_id' => $registration->site_id], $staff);
        });
    }

    public function verify(Request $request, ParentRegistration $registration, string $dob): void
    {
        $correct = DB::transaction(function () use ($registration, $dob): bool {
            $registration = ParentRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            abort_unless($registration->canEdit(), 410);
            if ($registration->child_date_of_birth->toDateString() !== $dob) {
                $registration->increment('failed_attempts');

                return false;
            }

            return true;
        });
        if (! $correct) {
            throw ValidationException::withMessages(['date_of_birth' => 'The date of birth did not match. Five incorrect attempts lock this invitation.']);
        }
        $request->session()->regenerate();
        $request->session()->put('parent_registration.'.$registration->uuid, $registration->access_key);
    }

    public function save(SaveParentRegistrationRequest $request, ParentRegistration $registration): ?AdultAccess
    {
        $stored = [];
        try {
            return DB::transaction(function () use ($request, $registration, &$stored): ?AdultAccess {
                Site::query()->whereKey($registration->site_id)->lockForUpdate()->firstOrFail();
                $registration = ParentRegistration::query()->lockForUpdate()->findOrFail($registration->id);
                abort_unless($registration->canEdit() && hash_equals($registration->access_key, (string) $request->query('key')), 410);
                $data = $request->validated();
                $previous = $registration->payload ?? [];
                $payload = Arr::only($data, ['name', 'also_known_as', 'adults']);
                $payload['photo_path'] = $previous['photo_path'] ?? null;
                if ($request->hasFile('new_photo')) {
                    $payload['photo_path'] = $request->file('new_photo')->store('registration-photos', 'local');
                    $stored[] = $payload['photo_path'];
                }
                $previousAdults = collect($previous['adults'] ?? [])->keyBy('key');
                foreach ($payload['adults'] as $index => &$adult) {
                    $adult = Arr::except($adult, ['new_photo']);
                    $adult['also_known_as'] ??= [];
                    $adult['email'] = $index === 0 ? $registration->email : (filled($adult['email'] ?? null) ? mb_strtolower(trim($adult['email'])) : null);
                    $adult['document_responsible'] = $index === 0 || $adult['document_responsible'];
                    $adult['is_manager'] = $index === 0;
                    $adult['authorised_to_collect'] = true;
                    $adult['photo_path'] = $previousAdults->get($adult['key'])['photo_path'] ?? null;
                    if ($request->hasFile("adults.$index.new_photo")) {
                        $adult['photo_path'] = $request->file("adults.$index.new_photo")->store('registration-photos', 'local');
                        $stored[] = $adult['photo_path'];
                    }
                    if ($data['action'] === 'submit' && $adult['document_responsible'] && blank($adult['email'])) {
                        throw ValidationException::withMessages(["adults.$index.email" => 'An email address is required for each adult responsible for documents.']);
                    }
                }
                unset($adult);
                $emails = array_filter(array_column($payload['adults'], 'email'));
                if (count($emails) !== count(array_unique($emails))) {
                    throw ValidationException::withMessages(['adults' => 'Each adult with online access must have a separate email address.']);
                }
                $access = null;
                if ($data['action'] === 'submit') {
                    foreach ($payload['adults'] as $index => &$adult) {
                        if (filled($adult['email'])) {
                            $profile = $this->profile($adult, $index, $previousAdults->get($adult['key']));
                            $adult['access_id'] = $profile->id;
                            if ($index === 0) {
                                $access = $profile;
                            }
                        }
                    }
                    unset($adult);
                    $this->documents->accept($access, $registration->site, array_values(array_map('intval', $data['documents'] ?? [])));
                }
                $registration->update([
                    'payload' => $payload, 'status' => $access ? 'submitted' : 'draft',
                    'adult_access_id' => $access?->id, 'submitted_at' => $access ? now() : null,
                    'revision' => $registration->revision + 1,
                ]);
                if ($access) {
                    foreach ($payload['adults'] as $index => $adult) {
                        if ($index > 0 && $adult['document_responsible']) {
                            $this->portal->sendLink(AdultAccess::query()->whereKey($adult['access_id'])->firstOrFail(), 'documents', $registration->site_id);
                        }
                    }
                    Mail::to($access->email)->send(new ParentActionMail('Registration submitted', 'Your registration is awaiting staff approval. Your registration link is now invalid. Bookmark this return link to view your registration status and request access to your adult page.', $this->portal->returnUrl($access), 'Your adult page'));
                    $request->session()->forget('parent_registration.'.$registration->uuid);
                    $this->audit->record('parent_registration_submitted', $registration, ['site_id' => $registration->site_id, 'actor_email' => $access->email]);
                }

                return $access;
            });
        } catch (\Throwable $exception) {
            $persistedPayload = ParentRegistration::query()->find($registration->id)->payload ?? [];
            $referenced = [];
            array_walk_recursive($persistedPayload, function (mixed $value) use (&$referenced): void {
                if (is_string($value)) {
                    $referenced[] = $value;
                }
            });
            Storage::disk('local')->delete(array_values(array_diff($stored, $referenced)));
            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $adult
     * @param  array<string, mixed>|null  $previous
     */
    private function profile(array $adult, int $index, ?array $previous): AdultAccess
    {
        $profile = AdultAccess::query()->where('email', $adult['email'])->lockForUpdate()->first();
        if ($profile && $profile->identityDateOfBirth() !== $adult['date_of_birth']) {
            if ($profile->adult_id === null && ($previous['access_id'] ?? null) === $profile->id && ($previous['email'] ?? null) === $adult['email']) {
                $profile->update(['name' => $adult['name'], 'date_of_birth' => $adult['date_of_birth']]);

                return $profile;
            }
            throw ValidationException::withMessages(["adults.$index.email" => 'These details need to be checked by the nursery. Please contact staff.']);
        }

        return $profile ?? AdultAccess::query()->create(Arr::only($adult, ['email', 'name', 'date_of_birth']));
    }

    /** @param array<string, mixed> $decisions */
    public function reviewDetails(ParentRegistration $registration, User $staff, array $decisions): void
    {
        DB::transaction(function () use ($registration, $staff, $decisions): void {
            $registration = ParentRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            abort_unless($registration->status === 'submitted' && $registration->revision === (int) $decisions['revision'], 409, 'This registration has changed. Refresh before reviewing.');
            $payload = $this->submittedPayload($registration);
            foreach ($payload['adults'] as $index => &$adult) {
                $decision = $decisions['adults'][$index] ?? [];
                $adult['authorised_to_collect'] = (bool) ($decision['authorised_to_collect'] ?? $adult['authorised_to_collect']);
                $adult['is_manager'] = (bool) ($decision['is_manager'] ?? $adult['is_manager']);
                $adult['document_responsible'] = (bool) ($decision['document_responsible'] ?? $adult['document_responsible']);
                if ($adult['document_responsible'] && ! isset($adult['access_id'])) {
                    throw ValidationException::withMessages(['adults' => 'Return the form for corrections to obtain an email address for each document-responsible adult.']);
                }
            }
            unset($adult);
            $registration->update(['payload' => $payload, 'revision' => $registration->revision + 1]);
            $this->audit->record('parent_registration_review_changed', $registration, ['site_id' => $registration->site_id], $staff);
        });
    }

    public function returnForCorrections(ParentRegistration $registration, User $staff, string $message, int $revision): void
    {
        DB::transaction(function () use ($registration, $staff, $message, $revision): void {
            $registration = ParentRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            abort_unless($registration->status === 'submitted' && $registration->revision === $revision, 409, 'This registration has changed. Refresh before returning it.');
            $registration->update(['status' => 'corrections', 'correction_message' => $message, 'reviewed_by' => $staff->id]);
            $this->resend($registration, $staff);
            $this->audit->record('parent_registration_returned', $registration, ['site_id' => $registration->site_id, 'message' => $message], $staff);
        });
    }

    /** @return array<string, mixed> */
    public function duplicates(ParentRegistration $registration): array
    {
        $payload = $this->submittedPayload($registration);
        $result = ['child' => $this->matches(new Child, $registration->site, $payload['name'], $registration->child_date_of_birth->toDateString()), 'adults' => []];
        foreach ($payload['adults'] as $index => $adult) {
            $result['adults'][$index] = $this->matches(new Adult, $registration->site, $adult['name'], $adult['date_of_birth']);
        }

        return $result;
    }

    /** @return list<array{id: int, name: string}> */
    private function matches(Adult|Child $person, Site $site, string $name, string $dob): array
    {
        return array_values($person->newQuery()->whereHas('sites', fn ($query) => $query->whereKey($site->id))
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])->whereDate('date_of_birth', $dob)
            ->orderBy('id')->get()->map(fn ($match): array => ['id' => $match->id, 'name' => $match->name])->all());
    }

    /** @param array<string, mixed> $data */
    public function approve(ParentRegistration $registration, User $staff, array $data): Child
    {
        $createdMedia = [];
        try {
            return DB::transaction(function () use ($registration, $staff, $data, &$createdMedia): Child {
                Site::query()->whereKey($registration->site_id)->lockForUpdate()->firstOrFail();
                $registration = ParentRegistration::query()->lockForUpdate()->findOrFail($registration->id);
                abort_unless($registration->status === 'submitted' && $registration->revision === (int) $data['revision'], 409, 'This registration has changed. Refresh before approving.');
                if ($this->documents->outstanding($registration) !== []) {
                    throw ValidationException::withMessages(['documents' => 'All nominated adults must accept the latest required documents before approval.']);
                }
                $payload = $this->submittedPayload($registration);
                $duplicates = $this->duplicates($registration);
                $child = $this->resolvePerson(Child::query(), $registration->site, ['name' => $payload['name'], 'date_of_birth' => $registration->child_date_of_birth->toDateString(), 'also_known_as' => $payload['also_known_as'] ?? [], 'photo_path' => $payload['photo_path'] ?? null], $duplicates['child'], $data['child_choice'] ?? null, 'child_choice', $createdMedia);
                $child->sites()->syncWithoutDetaching([$registration->site_id => ['is_active' => (bool) $data['is_active'], 'is_approved' => true]]);
                foreach ($payload['adults'] as $index => $adultData) {
                    $adult = $this->resolvePerson(Adult::query(), $registration->site, $adultData, $duplicates['adults'][$index], $data['adult_choices'][$index] ?? null, "adult_choices.$index", $createdMedia);
                    $profile = isset($adultData['access_id']) ? AdultAccess::query()->whereKey($adultData['access_id'])->lockForUpdate()->firstOrFail() : null;
                    if ($profile && $profile->adult_id !== null && $profile->adult_id !== $adult->id) {
                        throw ValidationException::withMessages(["adult_choices.$index" => 'This email already has an adult record. Link that record or contact an administrator.']);
                    }
                    if ($profile) {
                        if (AdultAccess::query()->where('adult_id', $adult->id)->whereKeyNot($profile->id)->exists()
                            || ($adult->email !== null && $adult->email !== $profile->email)) {
                            throw ValidationException::withMessages(["adult_choices.$index" => 'This existing adult has a different email or online-access record. Resolve their contact details through the verified email-change workflow before linking.']);
                        }
                        $profile->update(['adult_id' => $adult->id]);
                        if ($adult->email === null) {
                            if (Adult::query()->where('email', $profile->email)->whereKeyNot($adult->id)->exists()) {
                                throw ValidationException::withMessages(["adult_choices.$index" => 'This email is already recorded for another adult. Ask an administrator to resolve it.']);
                            }
                            $adult->update(['email' => $profile->email]);
                        }
                        if ($adult->phone === null) {
                            $adult->update(['phone' => $adultData['phone']]);
                        }
                    }
                    $adult->sites()->syncWithoutDetaching([$registration->site_id]);
                    if (ChildAdultSite::query()->where('site_id', $registration->site_id)->where('child_id', $child->id)->where('adult_id', $adult->id)->where('is_linked', false)->exists()) {
                        throw ValidationException::withMessages(["adult_choices.$index" => 'This adult was removed from the child at this nursery. Restore that site association explicitly before approving this link.']);
                    }
                    if (! $child->adults()->whereKey($adult->id)->exists()) {
                        $child->adults()->attach($adult->id, ['relationship' => $adultData['relationship'], 'authorised_to_collect' => $adultData['authorised_to_collect']]);
                    }
                    ChildAdultSite::query()->updateOrCreate(['site_id' => $registration->site_id, 'child_id' => $child->id, 'adult_id' => $adult->id], ['is_manager' => $adultData['is_manager'], 'document_responsible' => $adultData['document_responsible']]);
                }
                $registration->update(['status' => 'approved', 'child_id' => $child->id, 'approved_at' => now(), 'reviewed_by' => $staff->id, 'revision' => $registration->revision + 1]);
                $this->audit->record('parent_registration_approved', $registration, ['site_id' => $registration->site_id, 'child_id' => $child->id, 'is_active' => (bool) $data['is_active']], $staff);
                Mail::to($registration->email)->send(new ParentActionMail('Registration approved', 'The nursery has approved your child registration.', $this->portal->returnUrl(AdultAccess::query()->findOrFail($registration->adult_access_id)), 'Your adult page'));

                return $child;
            });
        } catch (\Throwable $exception) {
            foreach ($createdMedia as $photo) {
                if (! Media::query()->where('uuid', $photo->uuid)->exists()) {
                    $this->mediaFiles->removeAllFiles($photo);
                }
            }
            throw $exception;
        }
    }

    /**
     * @template T of Adult|Child
     *
     * @param  Builder<T>  $query
     * @param  array<string, mixed>  $data
     * @param  list<array{id: int, name: string}>  $duplicates
     * @param  list<Media>  $createdMedia
     * @return T
     */
    private function resolvePerson(Builder $query, Site $site, array $data, array $duplicates, mixed $choice, string $field, array &$createdMedia): Adult|Child
    {
        $person = $query->getModel();
        if ($duplicates !== [] && $choice === null) {
            throw ValidationException::withMessages([$field => 'Choose an existing person or explicitly create a different person.']);
        }
        if ($choice !== null && $choice !== 'new') {
            abort_unless(in_array((int) $choice, array_column($duplicates, 'id'), true), 422);

            return $query->lockForUpdate()->findOrFail((int) $choice);
        }
        if ($person instanceof Adult && filled($data['email'] ?? null) && Adult::query()->where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([$field => 'This email already belongs to another adult record. Contact an administrator to resolve it.']);
        }
        $person->fill(Arr::only($data, $person instanceof Adult ? ['name', 'date_of_birth', 'also_known_as', 'email', 'phone'] : ['name', 'date_of_birth', 'also_known_as']))->save();
        if (filled($data['photo_path'] ?? null)) {
            $createdMedia[] = $person->addMediaFromDisk($data['photo_path'], 'local')->preservingOriginal()->toMediaCollection('photo', 'local');
        }

        return $person;
    }

    /** @return array{name: string, adults: non-empty-list<array<string, mixed>>, also_known_as?: list<string>, photo_path?: string|null} */
    private function submittedPayload(ParentRegistration $registration): array
    {
        $payload = $registration->payload;
        if (! is_string($payload['name'] ?? null) || empty($payload['adults'])) {
            throw ValidationException::withMessages(['registration' => 'This registration is incomplete. Return it for corrections.']);
        }

        return $payload;
    }
}
