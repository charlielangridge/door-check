<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\AdultPortalAccess;
use App\Services\ParentDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocumentAcceptanceController extends Controller
{
    public function store(Request $request, Site $site, AdultPortalAccess $portal, ParentDocuments $documents): RedirectResponse
    {
        $access = $portal->current($request);
        abort_unless($documents->mayAccept($access, $site), 403);
        $portal->sendLink($access, 'documents', $site->id);

        return back()->with('parent_status', 'A personal document acceptance link has been queued for your email. Use your own date of birth to accept.');
    }
}
