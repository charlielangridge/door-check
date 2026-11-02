<?php

namespace App\Services;

use App\Mail\ParentActionMail;
use App\Models\Adult;
use App\Models\AdultAccess;
use App\Models\AdultAccessLink;
use App\Models\Child;
use App\Models\ChildAdultSite;
use App\Models\ParentUpdate;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ParentDetailsUpdates
{
    public function __construct(private readonly AdultPortalAccess $portal, private readonly CollectionAudit $audit, private readonly Filesystem $mediaFiles) {}

    public function canManage(AdultAccess $access, Site $site, Child $child): bool
    {
        return $this->siteAccess($access, $site)
            && $site->children()->whereKey($child->id)->exists()
            && $child->adults()->whereKey($access->adult_id)->exists()
            && ChildAdultSite::query()->where('site_id', $site->id)->where('adult_id', $access->adult_id)
                ->where('child_id', $child->id)->where('is_linked', true)->where('is_manager', true)->exists();
    }

    public function siteAccess(AdultAccess $access, Site $site): bool
    {
        return $access->adult_id !== null && $site->adults()->whereKey($access->adult_id)->wherePivot('access_suspended', false)->exists();
    }

    public function propose(Request $request, AdultAccess $access, Site $site): ParentUpdate
    {
        abort_unless($this->siteAccess($access, $site), 403);
        $target = $request->validate([
            'kind' => ['required', Rule::in(['adult', 'child', 'relationship', 'add_adult', 'remove_adult'])],
            'adult_id' => ['nullable', 'integer'], 'child_id' => ['nullable', 'integer'],
        ]);
        $kind = $target['kind'];
        $child = isset($target['child_id']) ? $site->children()->whereKey($target['child_id'])->firstOrFail() : null;
        $adult = isset($target['adult_id']) ? $site->adults()->whereKey($target['adult_id'])->firstOrFail() : null;
        $ownDetails = $kind === 'adult' && $adult?->id === $access->adult_id;
        abort_unless($ownDetails || ($child !== null && $this->canManage($access, $site, $child)), 403);
        if ($adult && ! $ownDetails) {
            abort_unless($child->adults()->whereKey($adult->id)->exists(), 404);
            abort_if(ChildAdultSite::query()->where('site_id', $site->id)->where('child_id', $child->id)->where('adult_id', $adult->id)->where('is_linked', false)->exists(), 404);
        }
        $rules = match ($kind) {
            'relationship' => ['relationship' => ['required', 'string', 'max:255'], 'authorised_to_collect' => ['required', 'boolean']],
            'remove_adult' => [],
            default => [
                'name' => ['required', 'string', 'max:255'],
                'also_known_as' => ['sometimes', 'array', 'max:50'],
                'also_known_as.*' => ['required', 'string', 'max:255', 'distinct'],
                'new_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
                ...(($kind === 'child' || $kind === 'add_adult' || $ownDetails) ? ['date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']] : []),
                ...($kind !== 'child' ? [
                    'phone' => ['required', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'],
                ] : []),
                ...($kind === 'add_adult' ? ['relationship' => ['required', 'string', 'max:255']] : []),
            ],
        };
        $payload = $request->validate($rules);
        if (in_array($kind, ['adult', 'child', 'add_adult'], true)) {
            $payload['also_known_as'] ??= [];
        }
        if (in_array($kind, ['adult', 'relationship', 'remove_adult'], true)) {
            abort_unless($adult !== null, 422);
        }
        if ($kind === 'child') {
            abort_unless($child !== null, 422);
        }
        if (isset($payload['email'])) {
            $payload['email'] = mb_strtolower(trim($payload['email']));
            $existing = AdultAccess::query()->where('email', $payload['email'])->first();
            if ($existing && $existing->adult_id !== $adult?->id) {
                throw ValidationException::withMessages(['email' => 'This email cannot be used for this adult. Ask the nursery to check the records.']);
            }
        }
        if ($ownDetails && blank($payload['email'] ?? null)) {
            throw ValidationException::withMessages(['email' => 'An email address is required for online access.']);
        }
        $path = $request->file('new_photo')?->store('parent-update-photos', 'local');
        $payload = Arr::except($payload, ['new_photo']);
        if ($path) {
            $payload['photo_path'] = $path;
        }
        $createdUpdateId = null;
        try {
            return DB::transaction(function () use ($access, $site, $adult, $child, $kind, $payload, &$createdUpdateId): ParentUpdate {
                $subject = $kind === 'child' ? $child : $adult;
                $original = $subject?->only(['name', 'date_of_birth', 'phone', 'email', 'also_known_as']);
                if ($subject) {
                    $original['photo_id'] = $subject->getMedia('*')->whereIn('collection_name', ['photo', 'logo'])->sortBy('id')->last()?->id;
                }
                if ($kind === 'relationship' || $kind === 'remove_adult') {
                    $original = DB::table('adult_child')->where('adult_id', $adult->id)->where('child_id', $child->id)->first();
                    $original = (array) $original;
                }
                $update = ParentUpdate::query()->create([
                    'adult_access_id' => $access->id, 'site_id' => $site->id, 'adult_id' => $adult?->id, 'child_id' => $child?->id,
                    'kind' => $kind, 'payload' => $payload, 'original' => $original,
                ]);
                $createdUpdateId = $update->id;
                if ($kind === 'adult' && filled($payload['email'] ?? null) && $payload['email'] !== $adult->email) {
                    $targetAccess = AdultAccess::query()->where('adult_id', $adult->id)->first();
                    if (! $targetAccess) {
                        throw ValidationException::withMessages(['email' => 'Ask staff to enable online access for this adult before changing their email.']);
                    }
                    $this->portal->sendLink($targetAccess, 'email_change', $site->id, $payload['email']);
                }
                $this->audit->record('parent_update_submitted', $update, ['site_id' => $site->id, 'actor_email' => $access->email]);

                return $update;
            });
        } catch (\Throwable $exception) {
            $persistedPath = $createdUpdateId ? (ParentUpdate::query()->find($createdUpdateId)?->payload['photo_path'] ?? null) : null;
            if ($path && $persistedPath !== $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function review(ParentUpdate $update, User $staff, array $data): void
    {
        $createdMedia = [];
        try {
            DB::transaction(function () use ($update, $staff, $data, &$createdMedia): void {
                $update = ParentUpdate::query()->lockForUpdate()->findOrFail($update->id);
                abort_unless($update->status === 'pending', 409, 'This update has already been reviewed.');
                $site = Site::query()->findOrFail($update->site_id);
                $access = AdultAccess::query()->findOrFail($update->adult_access_id);
                if ($data['action'] === 'reject_update') {
                    $update->update(['status' => 'rejected', 'reviewed_by' => $staff->id, 'review_message' => $data['message'] ?? null]);

                    return;
                }
                abort_unless($this->siteAccess($access, $site), 403, 'This adult no longer has access at this nursery.');
                $adult = $update->adult_id ? Adult::query()->lockForUpdate()->findOrFail($update->adult_id) : null;
                $child = $update->child_id ? Child::query()->lockForUpdate()->findOrFail($update->child_id) : null;
                $ownDetails = $update->kind === 'adult' && $adult?->id === $access->adult_id;
                abort_unless($ownDetails || ($child && $this->canManage($access, $site, $child)), 403, 'The parent no longer has management rights.');
                if ($adult && ! $ownDetails) {
                    abort_unless($site->adults()->whereKey($adult->id)->exists() && $child->adults()->whereKey($adult->id)->exists(), 404);
                    abort_if(ChildAdultSite::query()->where('site_id', $site->id)->where('child_id', $child->id)->where('adult_id', $adult->id)->where('is_linked', false)->exists(), 404);
                }
                $payload = $update->payload;
                if (in_array($update->kind, ['adult', 'child'], true)) {
                    $subject = $update->kind === 'adult' ? $adult : $child;
                    $current = json_decode(json_encode($subject->only(array_keys(Arr::except($update->original, ['photo_id']))), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                    if (array_key_exists('photo_id', $update->original)) {
                        $current['photo_id'] = $subject->getMedia('*')->whereIn('collection_name', ['photo', 'logo'])->sortBy('id')->last()?->id;
                    }
                    if ($current !== $update->original) {
                        throw ValidationException::withMessages(['update' => 'The approved details changed after this proposal. Ask the adult to submit a fresh update.']);
                    }
                    if ($subject instanceof Adult && filled($payload['email'] ?? null) && $payload['email'] !== $subject->email) {
                        if (! $update->email_verified_at) {
                            throw ValidationException::withMessages(['update' => 'The new email address must be verified before approval.']);
                        }
                        $profile = AdultAccess::query()->where('adult_id', $subject->id)->lockForUpdate()->firstOrFail();
                        if (AdultAccess::query()->where('email', $payload['email'])->whereKeyNot($profile->id)->exists()) {
                            throw ValidationException::withMessages(['update' => 'The new email address is already in use.']);
                        }
                        $profile->update(['email' => $payload['email'], 'uuid' => (string) Str::uuid(), 'session_version' => $profile->session_version + 1]);
                        AdultAccessLink::query()->where('adult_access_id', $profile->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                        Mail::to($profile->email)->send(new ParentActionMail('Your email address has changed', 'The nursery has approved your email change. Use your new permanent return link.', $this->portal->returnUrl($profile), 'Your adult page'));
                    }
                    $subject->update(Arr::except($payload, ['photo_path']));
                    if ($photo = $this->attachPhoto($subject, $payload)) {
                        $createdMedia[] = $photo;
                    }
                    if ($subject instanceof Adult) {
                        AdultAccess::query()->where('adult_id', $subject->id)->update(['name' => $subject->name, 'date_of_birth' => $subject->date_of_birth]);
                    }
                    foreach ($subject->sites()->whereKeyNot($site->id)->get() as $otherSite) {
                        foreach ($otherSite->staffUsers as $recipient) {
                            Mail::to($recipient->email)->send(new ParentActionMail('Shared person details updated', 'Another nursery has approved a change to a shared person record. Review the current details at your nursery.', route('staff.children.index', $otherSite->slug), 'Review details'));
                        }
                    }
                } elseif ($update->kind === 'relationship') {
                    $this->checkRelationshipUnchanged($update);
                    $child->adults()->updateExistingPivot($adult->id, $payload);
                } elseif ($update->kind === 'remove_adult') {
                    $this->checkRelationshipUnchanged($update);
                    ChildAdultSite::query()->updateOrCreate(['site_id' => $site->id, 'child_id' => $child->id, 'adult_id' => $adult->id], ['is_linked' => false, 'is_manager' => false, 'document_responsible' => false]);
                } elseif ($update->kind === 'add_adult') {
                    $matches = $site->adults()->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($payload['name']))])->whereDate('date_of_birth', $payload['date_of_birth'])->get();
                    $choice = $data['adult_choice'] ?? null;
                    if ($matches->isNotEmpty() && $choice === null) {
                        throw ValidationException::withMessages(['adult_choice' => 'Choose an existing adult or explicitly create a different person.']);
                    }
                    if ($choice && $choice !== 'new') {
                        $adult = $matches->firstWhere('id', (int) $choice);
                        abort_unless($adult !== null, 422);
                    } else {
                        if (filled($payload['email'] ?? null) && Adult::query()->where('email', $payload['email'])->exists()) {
                            throw ValidationException::withMessages(['adult_choice' => 'This email is already recorded. Ask an administrator to resolve the adult record.']);
                        }
                        $adult = Adult::query()->create(Arr::only($payload, ['name', 'date_of_birth', 'email', 'phone', 'also_known_as']));
                        if ($photo = $this->attachPhoto($adult, $payload)) {
                            $createdMedia[] = $photo;
                        }
                    }
                    $adult->sites()->syncWithoutDetaching([$site->id]);
                    $excluded = ChildAdultSite::query()->where('site_id', $site->id)->where('child_id', $child->id)->where('adult_id', $adult->id)->where('is_linked', false)->exists();
                    if ($child->adults()->whereKey($adult->id)->exists() && ! $excluded) {
                        throw ValidationException::withMessages(['adult_choice' => 'This adult is already linked. Review that relationship instead.']);
                    }
                    if (! $child->adults()->whereKey($adult->id)->exists()) {
                        $child->adults()->attach($adult->id, ['relationship' => $payload['relationship'], 'authorised_to_collect' => true]);
                    }
                    ChildAdultSite::query()->updateOrCreate(['site_id' => $site->id, 'child_id' => $child->id, 'adult_id' => $adult->id], ['is_linked' => true]);
                }
                $update->update(['status' => 'approved', 'reviewed_by' => $staff->id]);
                $this->audit->record('parent_update_approved', $update, ['site_id' => $site->id, 'kind' => $update->kind], $staff);
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

    private function checkRelationshipUnchanged(ParentUpdate $update): void
    {
        $current = (array) DB::table('adult_child')->where('adult_id', $update->adult_id)->where('child_id', $update->child_id)->lockForUpdate()->first();
        if ($current != $update->original) {
            throw ValidationException::withMessages(['update' => 'The relationship has changed. Ask the parent to submit a fresh update.']);
        }
    }

    /** @param array<string, mixed> $payload */
    private function attachPhoto(Adult|Child $subject, array $payload): ?Media
    {
        if (filled($payload['photo_path'] ?? null)) {
            return $subject->addMediaFromDisk($payload['photo_path'], 'local')->preservingOriginal()->toMediaCollection('photo', 'local');
        }

        return null;
    }
}
