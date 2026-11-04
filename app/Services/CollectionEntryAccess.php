<?php

namespace App\Services;

use App\Events\CollectionEntryClaimed;
use App\Models\CollectionEntry;
use App\Models\CollectionEntryDisplay;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionEntryAccess
{
    public function browserKey(Request $request): string
    {
        $key = $request->cookie('collection_browser');

        return is_string($key) && strlen($key) === 64 ? $key : Str::random(64);
    }

    public function current(CollectionEntryDisplay $display, bool $replace = false): CollectionEntry
    {
        return DB::transaction(function () use ($display, $replace): CollectionEntry {
            $display = CollectionEntryDisplay::query()->whereKey($display->getKey())->lockForUpdate()->firstOrFail();

            return $this->currentWhileLocked($display, $replace);
        }, 3);
    }

    private function currentWhileLocked(CollectionEntryDisplay $display, bool $replace = false): CollectionEntry
    {
        $entry = $display->entries()->latest('id')->first();
        if (! $replace && $entry !== null && $entry->claimed_at === null && $entry->token_expires_at->isFuture()) {
            return $entry;
        }

        if ($entry !== null && $entry->claimed_at === null && $entry->token_expires_at->isFuture()) {
            $entry->token_expires_at = now();
            $entry->save();
        }

        return $display->entries()->create(['token_expires_at' => now()->addSeconds(60)]);
    }

    public function claim(Site $site, CollectionEntry $entry, string $browserKey): ?CollectionEntry
    {
        return DB::transaction(function () use ($site, $entry, $browserKey): ?CollectionEntry {
            $display = CollectionEntryDisplay::query()->whereKey($entry->collection_entry_display_id)
                ->where('site_id', $site->getKey())->lockForUpdate()->firstOrFail();
            $entry = CollectionEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            if ($entry->claimed_at !== null) {
                return $this->ownedBy($entry, $browserKey) && $this->canSubmitOrResume($entry) ? $entry : null;
            }

            if (! $entry->token_expires_at->isFuture()) {
                return null;
            }

            $entry->forceFill([
                'claimed_at' => now(),
                'form_expires_at' => now()->addMinutes(15),
                'browser_key_hash' => hash('sha256', $browserKey),
            ])->save();
            $this->currentWhileLocked($display);

            DB::afterCommit(function () use ($display): void {
                rescue(fn () => event(new CollectionEntryClaimed($display->uuid)));
            });

            return $entry;
        }, 3);
    }

    public function allows(Request $request, Site $site, CollectionEntry $entry): bool
    {
        $key = $request->cookie('collection_browser');

        return is_string($key) && strlen($key) === 64 && $this->allowsKey($site, $entry, $key);
    }

    public function allowsKey(Site $site, CollectionEntry $entry, string $key): bool
    {
        return $entry->display()->where('site_id', $site->getKey())->exists()
            && $this->ownedBy($entry, $key) && $this->canSubmitOrResume($entry);
    }

    public function startKiosk(Request $request, Site $site, CollectionEntryDisplay $display): CollectionEntry
    {
        $key = Str::random(64);
        $entry = DB::transaction(function () use ($site, $display, $key): CollectionEntry {
            $display = CollectionEntryDisplay::query()->whereKey($display->getKey())
                ->where('site_id', $site->getKey())->lockForUpdate()->firstOrFail();
            $entry = $this->claim($site, $this->currentWhileLocked($display), $key);
            abort_if($entry === null, 409);

            return $entry;
        }, 3);
        $request->session()->put('collection_kiosk.'.$display->uuid, ['entry' => $entry->uuid, 'key' => $key]);

        return $entry;
    }

    public function kioskKey(Request $request, CollectionEntryDisplay $display, CollectionEntry $entry): ?string
    {
        $grant = $request->session()->get('collection_kiosk.'.$display->uuid);
        if (! is_array($grant) || ($grant['entry'] ?? null) !== $entry->uuid || ! is_string($grant['key'] ?? null)) {
            return null;
        }

        return $grant['key'];
    }

    private function ownedBy(CollectionEntry $entry, string $browserKey): bool
    {
        return is_string($entry->browser_key_hash)
            && hash_equals($entry->browser_key_hash, hash('sha256', $browserKey));
    }

    private function canSubmitOrResume(CollectionEntry $entry): bool
    {
        return $entry->collection_request_id !== null || $entry->form_expires_at?->isFuture() === true;
    }
}
