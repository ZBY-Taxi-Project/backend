<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Dispatcher\DriverController as DispatcherDriverController;
use App\Http\Controllers\Dispatcher\OrderController as DispatcherOrderController;
use App\Http\Controllers\Driver\OrderController as DriverOrderController;
use App\Http\Controllers\Driver\ProfileController as DriverProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Authentication
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// Dispatcher Operations (Protected by Sanctum & Dispatcher/Admin role)
Route::prefix('dispatcher')
    ->middleware(['auth:sanctum', 'role:dispatcher,admin'])
    ->group(function () {
        // Orders
        Route::get('/orders', [DispatcherOrderController::class, 'index']);
        Route::post('/orders', [DispatcherOrderController::class, 'store']);
        Route::get('/orders/{id}', [DispatcherOrderController::class, 'show']);
        Route::post('/orders/{id}/assign', [DispatcherOrderController::class, 'assign']);
        Route::post('/orders/{id}/reassign', [DispatcherOrderController::class, 'reassign']);
        Route::post('/orders/{id}/cancel-assignment', [DispatcherOrderController::class, 'cancelAssignment']);
        Route::post('/orders/{id}/cancel', [DispatcherOrderController::class, 'cancelOrder']);

        // Drivers
        Route::get('/drivers', [DispatcherDriverController::class, 'index']);
        Route::get('/drivers/available', [DispatcherDriverController::class, 'available']);
    });

// Driver Operations (Protected by Sanctum & Driver role)
Route::prefix('driver')
    ->middleware(['auth:sanctum', 'role:driver'])
    ->group(function () {
        // Orders & Assignments
        Route::get('/order/active', [DriverOrderController::class, 'activeOrder']);
        Route::get('/assignment/pending', [DriverOrderController::class, 'pendingAssignment']);
        Route::post('/assignments/{id}/accept', [DriverOrderController::class, 'acceptAssignment']);
        Route::post('/assignments/{id}/reject', [DriverOrderController::class, 'rejectAssignment']);
        Route::post('/orders/{id}/status', [DriverOrderController::class, 'updateStatus']);
        Route::get('/history', [DriverOrderController::class, 'history']);

        // Driver Profile Status & Location
        Route::post('/profile/status', [DriverProfileController::class, 'updateStatus']);
        Route::post('/profile/location', [DriverProfileController::class, 'updateLocation']);
    });
