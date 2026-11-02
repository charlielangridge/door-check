<?php

namespace App\Services;

use App\Mail\ParentActionMail;
use App\Models\Adult;
use App\Models\AdultAccess;
use App\Models\AdultAccessLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class AdultPortalAccess
{
    public function returnUrl(AdultAccess $access): string
    {
        return URL::signedRoute('adult-access.show', ['access' => $access->uuid]);
    }

    public function forAdult(Adult $adult): AdultAccess
    {
        if (blank($adult->email)) {
            throw ValidationException::withMessages(['adult_id' => 'Record an email address before enabling online access.']);
        }

        return DB::transaction(function () use ($adult): AdultAccess {
            $adult = Adult::query()->lockForUpdate()->findOrFail($adult->id);
            $profile = AdultAccess::query()->where('adult_id', $adult->id)->lockForUpdate()->first();
            if ($profile) {
                return $profile;
            }
            $profile = AdultAccess::query()->where('email', $adult->email)->lockForUpdate()->first();
            if ($profile) {
                if ($profile->adult_id !== null || $profile->identityDateOfBirth() !== $adult->date_of_birth->toDateString()) {
                    throw ValidationException::withMessages(['adult_id' => 'This email already identifies a different adult. Ask an administrator to resolve it.']);
                }
                $profile->update(['adult_id' => $adult->id]);

                return $profile;
            }

            return AdultAccess::query()->create(['adult_id' => $adult->id, 'email' => $adult->email, 'name' => $adult->name, 'date_of_birth' => $adult->date_of_birth]);
        });
    }

    public function sendLink(AdultAccess $access, string $purpose = 'login', ?int $siteId = null, ?string $email = null): AdultAccessLink
    {
        return DB::transaction(function () use ($access, $purpose, $siteId, $email): AdultAccessLink {
            $access = AdultAccess::query()->lockForUpdate()->findOrFail($access->id);
            AdultAccessLink::query()->where('adult_access_id', $access->id)->where('purpose', $purpose)
                ->where('site_id', $siteId)->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $link = AdultAccessLink::query()->create([
                'adult_access_id' => $access->id, 'site_id' => $siteId, 'purpose' => $purpose,
                'email' => $email ?? $access->email, 'expires_at' => $purpose === 'documents' ? now()->addDays(7) : now()->addMinutes(15),
            ]);
            $url = URL::temporarySignedRoute('adult-links.show', $link->expires_at, ['link' => $link->uuid]);
            Mail::to($link->email)->send(new ParentActionMail(
                $purpose === 'documents' ? 'Please accept the nursery documents' : ($purpose === 'email_change' ? 'Verify your new email address' : 'Access your adult page'),
                $purpose === 'documents' ? 'Use this link within seven days. You will need your own date of birth.' : 'This one-use link expires in 15 minutes.',
                $url,
                permanentReturnUrl: $this->returnUrl($access),
            ));

            return $link;
        });
    }

    public function current(Request $request): AdultAccess
    {
        $session = $request->session()->get('adult_portal', []);
        $access = AdultAccess::query()->whereKey($session['id'] ?? 0)->first();
        if (! $access || ($session['version'] ?? null) !== $access->session_version || ($session['last_activity'] ?? 0) <= now()->subMinutes(30)->timestamp) {
            $request->session()->forget('adult_portal');
            abort(401, 'Your adult-page session has expired. Open your permanent return link to request a fresh access email.');
        }
        $request->session()->put('adult_portal.last_activity', now()->timestamp);

        return $access;
    }

    public function start(Request $request, AdultAccess $access): void
    {
        $request->session()->regenerate();
        $request->session()->put('adult_portal', ['id' => $access->id, 'version' => $access->session_version, 'last_activity' => now()->timestamp]);
    }

    public function verifyDob(Request $request, AdultAccessLink $link): void
    {
        $dob = $request->validate(['date_of_birth' => ['required', 'date_format:Y-m-d']])['date_of_birth'];
        $correct = DB::transaction(function () use ($link, $dob): bool {
            $locked = AdultAccessLink::query()->lockForUpdate()->findOrFail($link->id);
            abort_unless($locked->isUsable(), 410, 'This link is expired, locked or has already been used. Ask the nursery for a fresh link.');
            if ($dob !== $locked->access->identityDateOfBirth()) {
                $locked->increment('failed_attempts');

                return false;
            }

            return true;
        });
        if (! $correct) {
            throw ValidationException::withMessages(['date_of_birth' => 'The date of birth did not match. Five incorrect attempts lock this link.']);
        }
        $request->session()->put('adult_document_links.'.$link->uuid, true);
    }
}
