<?php

use App\Http\Controllers\CollectionRequestController;
use App\Http\Controllers\CollectionStatusController;
use App\Http\Controllers\StaffCollectionController;
use App\Http\Controllers\StaffMediaController;
use App\Http\Middleware\EnsureStaffSession;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::get('admin/person-photos/{media}', [StaffMediaController::class, 'showForAdmin'])->middleware(['auth', 'can:access admin'])->name('admin.media.show');

Route::get('staff', [StaffCollectionController::class, 'index'])->middleware(['auth', 'verified', EnsureStaffSession::class])->name('staff.index');

Route::middleware(['auth', 'verified', EnsureStaffSession::class])->prefix('staff/{site:slug}')->name('staff.')->group(function (): void {
    Route::get('/', [StaffCollectionController::class, 'board'])->name('board');
    Route::get('/media/{media}', [StaffMediaController::class, 'show'])->name('media.show');
    Route::get('/collections/{collectionRequest:uuid}', [StaffCollectionController::class, 'show'])->name('collections.show');
    Route::get('/collections/{collectionRequest:uuid}/data', [StaffCollectionController::class, 'data'])->name('collections.data');
    Route::get('/collections/{collectionRequest:uuid}/candidates', [StaffCollectionController::class, 'candidates'])->name('collections.candidates');
    Route::get('/collections/{collectionRequest:uuid}/timeline', [StaffCollectionController::class, 'timeline'])->name('collections.timeline');
    Route::get('/collections/{collectionRequest:uuid}/checks', [StaffCollectionController::class, 'checks'])->name('collections.checks');
    Route::post('/collections/{collectionRequest:uuid}', [StaffCollectionController::class, 'update'])->name('collections.update');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

Route::get('collection-request/{site:slug}', [CollectionRequestController::class, 'create'])->name('collection-request.create');
Route::post('collection-request/{site:slug}', [CollectionRequestController::class, 'store'])
    ->middleware(HandlePrecognitiveRequests::class)
    ->name('collection-request.store');

Route::get('collection-status/{collectionRequest:uuid}', [CollectionStatusController::class, 'show'])->name('collection-status.show');
Route::post('collection-status/{collectionRequest:uuid}/children', [CollectionStatusController::class, 'store'])->name('collection-status.children.store')->middleware('throttle:20,1');
