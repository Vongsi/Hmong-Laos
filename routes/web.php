<?php

use App\Http\Controllers\PwaController;
use Illuminate\Support\Facades\Route;

Route::get('manifest/{site}.webmanifest', [PwaController::class, 'manifest'])->where('site', '[a-z]+');

Route::middleware(['auth', 'throttle:20,1'])->group(function () {
    Route::post('push/subscriptions', [PwaController::class, 'subscribe']);
    Route::delete('push/subscriptions', [PwaController::class, 'unsubscribe']);
});
