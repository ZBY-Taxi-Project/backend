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

// Distance & Road Pricing Estimation (Accessible to Flutter and Web apps)
Route::post('/pricing/estimate', [\App\Http\Controllers\PricingController::class, 'estimate']);

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
        Route::put('/orders/{id}', [DispatcherOrderController::class, 'update']);
        Route::patch('/orders/{id}', [DispatcherOrderController::class, 'update']);
        Route::post('/orders/{id}/assign', [DispatcherOrderController::class, 'assign']);
        Route::post('/orders/{id}/reassign', [DispatcherOrderController::class, 'reassign']);
        Route::post('/orders/{id}/cancel-assignment', [DispatcherOrderController::class, 'cancelAssignment']);
        Route::post('/orders/{id}/cancel', [DispatcherOrderController::class, 'cancelOrder']);

        // Realtime sync & stream
        Route::get('/sync', [\App\Http\Controllers\RealtimeController::class, 'dispatcherSync']);
        Route::get('/stream', [\App\Http\Controllers\RealtimeController::class, 'dispatcherStream']);

        // Drivers
        Route::get('/drivers', [DispatcherDriverController::class, 'index']);
        Route::post('/drivers', [DispatcherDriverController::class, 'store']);
        Route::put('/drivers/{id}', [DispatcherDriverController::class, 'update']);
        Route::delete('/drivers/{id}', [DispatcherDriverController::class, 'destroy']);
        Route::get('/drivers/available', [DispatcherDriverController::class, 'available']);
        Route::post('/drivers/{id}/wallet/topup', [DispatcherDriverController::class, 'topupWallet']);

        // Wallet Top-up Requests & Verification (Permission Protected)
        Route::get('/wallet/topup-requests', [\App\Http\Controllers\Dispatcher\WalletTopupRequestController::class, 'index']);
        Route::post('/wallet/topup-requests/{id}/approve', [\App\Http\Controllers\Dispatcher\WalletTopupRequestController::class, 'approve']);
        Route::post('/wallet/topup-requests/{id}/reject', [\App\Http\Controllers\Dispatcher\WalletTopupRequestController::class, 'reject']);
        Route::post('/wallet/direct-delivery', [\App\Http\Controllers\Dispatcher\WalletTopupRequestController::class, 'directDelivery']);

        // System Permissions (Admin / Dispatcher Roles)
        Route::get('/permissions', [\App\Http\Controllers\Dispatcher\PermissionController::class, 'index']);
        Route::post('/users/{id}/permissions', [\App\Http\Controllers\Dispatcher\PermissionController::class, 'updateUserPermissions']);
        Route::post('/users/{id}/role', [\App\Http\Controllers\Dispatcher\PermissionController::class, 'updateUserRole']);
    });

// Driver Operations (Protected by Sanctum & Driver role)
Route::prefix('driver')
    ->middleware(['auth:sanctum', 'role:driver'])
    ->group(function () {
        // Orders & Assignments
        Route::get('/sync', [\App\Http\Controllers\RealtimeController::class, 'driverSync']);
        Route::get('/order/active', [DriverOrderController::class, 'activeOrder']);
        Route::get('/orders/active', [DriverOrderController::class, 'activeOrder']);
        Route::get('/assignment/pending', [DriverOrderController::class, 'pendingAssignment']);
        Route::get('/assignments/pending', [DriverOrderController::class, 'pendingAssignment']);
        Route::post('/assignments/{id}/accept', [DriverOrderController::class, 'acceptAssignment']);
        Route::post('/assignment/{id}/accept', [DriverOrderController::class, 'acceptAssignment']);
        Route::post('/assignments/{id}/reject', [DriverOrderController::class, 'rejectAssignment']);
        Route::post('/assignment/{id}/reject', [DriverOrderController::class, 'rejectAssignment']);
        Route::post('/orders/{id}/status', [DriverOrderController::class, 'updateStatus']);
        Route::post('/order/{id}/status', [DriverOrderController::class, 'updateStatus']);
        Route::get('/history', [DriverOrderController::class, 'history']);
        Route::get('/orders/history', [DriverOrderController::class, 'history']);

        // Driver Wallet & Commission
        Route::get('/wallet', [\App\Http\Controllers\Driver\WalletController::class, 'getWallet']);
        Route::post('/wallet/topup', [\App\Http\Controllers\Driver\WalletController::class, 'topUp']);
        Route::post('/wallet/request-topup', [\App\Http\Controllers\Driver\WalletController::class, 'submitTopupRequest']);
        Route::get('/wallet/transactions', [\App\Http\Controllers\Driver\WalletController::class, 'transactions']);

        // Driver Profile Status & Location
        Route::post('/profile/status', [DriverProfileController::class, 'updateStatus']);
        Route::post('/status', [DriverProfileController::class, 'updateStatus']);
        Route::post('/profile/location', [DriverProfileController::class, 'updateLocation']);
        Route::post('/location', [DriverProfileController::class, 'updateLocation']);
    });

    // Telegram Bot Integration & Webhook for Check Delivery
    Route::prefix('telegram')->group(function () {
        Route::post('/webhook', [\App\Http\Controllers\Telegram\TelegramWebhookController::class, 'handleWebhook']);
        Route::get('/bot-info', [\App\Http\Controllers\Telegram\TelegramWebhookController::class, 'getBotInfo']);
        Route::post('/simulate-incoming-check', [\App\Http\Controllers\Telegram\TelegramWebhookController::class, 'simulateIncomingCheck']);
    });
