<?php

use App\Http\Controllers\MockInsurerController;
use App\Http\Controllers\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - TripperShield PolicyFlow
|--------------------------------------------------------------------------
*/

Route::prefix('policy-requests')->group(function () {
    Route::post('/', [PolicyRequestController::class, 'store'])->name('policy-requests.store');
    Route::get('/', [PolicyRequestController::class, 'index'])->name('policy-requests.index');
    Route::get('/{id}', [PolicyRequestController::class, 'show'])->name('policy-requests.show');
    Route::post('/{id}/submit', [PolicyRequestController::class, 'submit'])->name('policy-requests.submit');
    Route::post('/{id}/reconcile', [PolicyRequestController::class, 'reconcile'])->name('policy-requests.reconcile');
});

Route::prefix('mock-insurer')->group(function () {
    Route::post('/callback', [MockInsurerController::class, 'callback'])->name('mock-insurer.callback');
    Route::get('/lookup', [MockInsurerController::class, 'lookup'])->name('mock-insurer.lookup');
});
