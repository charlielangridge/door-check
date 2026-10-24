<?php

use App\Http\Controllers\CollectionRequestController;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

Route::get('collection-request/{site:uuid}', [CollectionRequestController::class, 'create'])->name('collection-request.create');
Route::post('collection-request/{site:uuid}', [CollectionRequestController::class, 'store'])
    ->middleware(HandlePrecognitiveRequests::class)
    ->name('collection-request.store');
