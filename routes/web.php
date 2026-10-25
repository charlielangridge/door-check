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

Route::middleware(['auth', 'verified', 'can:access admin'])->prefix('admin/staff')->name('admin.staff.')->group(function (): void {
    Route::get('/sites/{site:uuid}', [StaffCollectionController::class, 'site'])->name('board');
    Route::get('/collections/{collectionRequest}', [StaffCollectionController::class, 'show'])->name('collections.show');
    Route::get('/collections/{collectionRequest}/data', [StaffCollectionController::class, 'data'])->name('collections.data');
    Route::get('/collections/{collectionRequest}/candidates', [StaffCollectionController::class, 'candidates'])->name('collections.candidates');
    Route::get('/collections/{collectionRequest}/timeline', [StaffCollectionController::class, 'timeline'])->name('collections.timeline');
    Route::get('/collections/{collectionRequest}/checks', [StaffCollectionController::class, 'checks'])->name('collections.checks');
    Route::post('/collections/{collectionRequest}', [StaffCollectionController::class, 'update'])->name('collections.update');
});

Route::middleware(['auth', 'verified', EnsureStaffSession::class])->prefix('staff')->name('staff.')->group(function (): void {
    Route::get('/', [StaffCollectionController::class, 'index'])->name('board');
    Route::get('/media/{media}', [StaffMediaController::class, 'show'])->name('media.show');
    Route::get('/collections/{collectionRequest}', [StaffCollectionController::class, 'show'])->name('collections.show');
    Route::get('/collections/{collectionRequest}/data', [StaffCollectionController::class, 'data'])->name('collections.data');
    Route::get('/collections/{collectionRequest}/candidates', [StaffCollectionController::class, 'candidates'])->name('collections.candidates');
    Route::get('/collections/{collectionRequest}/timeline', [StaffCollectionController::class, 'timeline'])->name('collections.timeline');
    Route::get('/collections/{collectionRequest}/checks', [StaffCollectionController::class, 'checks'])->name('collections.checks');
    Route::post('/collections/{collectionRequest}', [StaffCollectionController::class, 'update'])->name('collections.update');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

Route::get('collection-request/{site:uuid}', [CollectionRequestController::class, 'create'])->name('collection-request.create');
Route::post('collection-request/{site:uuid}', [CollectionRequestController::class, 'store'])
    ->middleware(HandlePrecognitiveRequests::class)
    ->name('collection-request.store');

Route::get('collection-status/{collectionRequest}', [CollectionStatusController::class, 'show'])->name('collection-status.show');
Route::post('collection-status/{collectionRequest}/children', [CollectionStatusController::class, 'store'])->name('collection-status.children.store')->middleware('throttle:20,1');
