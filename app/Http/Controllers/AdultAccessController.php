<?php

namespace App\Http\Controllers;

use App\Models\AdultAccess;
use App\Models\AdultAccessLink;
use App\Models\ParentUpdate;
use App\Models\Site;
use App\Services\AdultPortalAccess;
use App\Services\ParentDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdultAccessController extends Controller
{
    public function show(Request $request, AdultAccess $access): Response
    {
        return Inertia::render('Parents/Access', ['mode' => 'request', 'uuid' => $access->uuid, 'signedQuery' => $request->query(), 'sent' => session('link_sent', false)]);
    }

    public function store(AdultAccess $access, AdultPortalAccess $portal): RedirectResponse
    {
        $portal->sendLink($access);

        return back()->with('link_sent', true);
    }

    public function showLink(Request $request, AdultAccessLink $link, ParentDocuments $documents): Response
    {
        abort_unless($link->isUsable(), 410, 'This link is expired, locked or has already been used. Request a fresh email.');
        $verified = $link->purpose === 'documents' && $request->session()->get('adult_document_links.'.$link->uuid) === true;
        if ($verified) {
            abort_unless($documents->mayAccept($link->access, Site::query()->findOrFail($link->site_id)), 403);
        }

        return Inertia::render('Parents/Access', [
            'mode' => $link->purpose, 'uuid' => $link->uuid, 'signedQuery' => $request->query(), 'verified' => $verified,
            'documents' => $verified ? $documents->display(Site::query()->findOrFail($link->site_id), $link->access) : [],
        ]);
    }

    public function consume(Request $request, AdultAccessLink $link, AdultPortalAccess $portal, ParentDocuments $documents): RedirectResponse
    {
        abort_unless($link->isUsable(), 410);
        if ($link->purpose === 'documents') {
            abort_unless($documents->mayAccept($link->access, Site::query()->findOrFail($link->site_id)), 403);
            if (! $request->session()->get('adult_document_links.'.$link->uuid)) {
                $portal->verifyDob($request, $link);

                return back();
            }
            $data = $request->validate(['documents' => ['sometimes', 'array', 'max:100'], 'documents.*' => ['integer', 'distinct']]);
            DB::transaction(function () use ($request, $link, $documents, $data): void {
                Site::query()->whereKey($link->site_id)->lockForUpdate()->firstOrFail();
                $link = AdultAccessLink::query()->lockForUpdate()->findOrFail($link->id);
                abort_unless($link->isUsable(), 410);
                $documents->accept($link->access, Site::query()->findOrFail($link->site_id), array_values(array_map('intval', $data['documents'] ?? [])));
                $link->update(['used_at' => now()]);
                $request->session()->forget('adult_document_links.'.$link->uuid);
            });

            return to_route('parent-action.complete');
        }
        DB::transaction(function () use ($request, $link, $portal): void {
            $link = AdultAccessLink::query()->lockForUpdate()->findOrFail($link->id);
            abort_unless($link->isUsable(), 410);
            $link->update(['used_at' => now()]);
            if ($link->purpose === 'login') {
                $portal->start($request, $link->access);
            } elseif ($link->purpose === 'email_change') {
                ParentUpdate::query()->where('adult_id', $link->access->adult_id)->where('status', 'pending')
                    ->where('kind', 'adult')->get()->filter(fn (ParentUpdate $update): bool => ($update->payload['email'] ?? null) === $link->email)
                    ->each(fn (ParentUpdate $update) => $update->update(['email_verified_at' => now()]));
            } else {
                abort(422);
            }
        });

        return $link->purpose === 'login' ? to_route('adult-portal.index') : to_route('parent-action.complete');
    }
}
