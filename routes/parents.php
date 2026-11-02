<?php

use App\Http\Controllers\AdultAccessController;
use App\Http\Controllers\AdultPortalController;
use App\Http\Controllers\DocumentAcceptanceController;
use App\Http\Controllers\ParentMediaController;
use App\Http\Controllers\ParentRegistrationController;
use App\Http\Controllers\SiteDocumentController;
use App\Http\Controllers\StaffParentRegistrationController;
use App\Http\Middleware\EnsureStaffSession;
use App\Http\Middleware\PrivateFamilyResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(PrivateFamilyResponse::class)->group(function (): void {
    Route::get('parent-registration/{registration:uuid}', [ParentRegistrationController::class, 'show'])->middleware('signed')->name('parent-registration.show');
    Route::post('parent-registration/{registration:uuid}', [ParentRegistrationController::class, 'store'])->middleware(['signed', 'throttle:30,1'])->name('parent-registration.store');
    Route::get('adult-access/{access:uuid}', [AdultAccessController::class, 'show'])->middleware('signed')->name('adult-access.show');
    Route::post('adult-access/{access:uuid}', [AdultAccessController::class, 'store'])->middleware(['signed', 'throttle:3,1'])->name('adult-access.store');
    Route::get('adult-links/{link:uuid}', [AdultAccessController::class, 'showLink'])->middleware('signed')->name('adult-links.show');
    Route::post('adult-links/{link:uuid}', [AdultAccessController::class, 'consume'])->middleware(['signed', 'throttle:20,1'])->name('adult-links.consume');
    Route::get('adult-page', [AdultPortalController::class, 'index'])->name('adult-portal.index');
    Route::post('adult-page/{site:slug}/updates', [AdultPortalController::class, 'store'])->middleware('throttle:20,1')->name('adult-portal.updates.store');
    Route::post('adult-page/{site:slug}/documents', [DocumentAcceptanceController::class, 'store'])->middleware('throttle:20,1')->name('adult-portal.documents.store');
    Route::delete('adult-page', [AdultPortalController::class, 'destroy'])->name('adult-portal.destroy');
    Route::get('parent-documents/{version}', [ParentMediaController::class, 'document'])->name('parent-documents.show');
    Route::get('adult-page/photos/{media}', [ParentMediaController::class, 'photo'])->name('adult-portal.photos.show');
    Route::inertia('parent-action-complete', 'Parents/Complete')->name('parent-action.complete');

    Route::middleware(['auth', 'verified', EnsureStaffSession::class])->prefix('staff/{site:slug}')->name('staff.')->group(function (): void {
        Route::get('registrations', [StaffParentRegistrationController::class, 'index'])->name('registrations.index');
        Route::post('registrations', [StaffParentRegistrationController::class, 'store'])->name('registrations.store');
        Route::post('registrations/{registration:uuid}', [StaffParentRegistrationController::class, 'update'])->name('registrations.update');
        Route::post('parent-updates/{update}', [StaffParentRegistrationController::class, 'reviewUpdate'])->name('parent-updates.review');
        Route::post('children/{child:uuid}/membership', [StaffParentRegistrationController::class, 'membership'])->name('children.membership');
        Route::get('registrations/{registration:uuid}/photos/{slot}', [ParentMediaController::class, 'registrationPhoto'])->name('registrations.photo');
        Route::get('parent-updates/{update}/photo', [ParentMediaController::class, 'updatePhoto'])->name('parent-updates.photo');
        Route::get('documents', [SiteDocumentController::class, 'index'])->name('documents.index');
        Route::post('documents', [SiteDocumentController::class, 'store'])->name('documents.store');
    });
});
