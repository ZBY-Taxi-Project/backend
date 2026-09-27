<?php

namespace App\Http\Controllers\Dispatcher;

use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\DriverWalletTransaction;
use App\Models\WalletTopupRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WalletTopupRequestController extends Controller
{
    /**
     * List all driver wallet top-up requests.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Permission check
        if (! $user->hasPermission('wallet.view') && ! $user->hasPermission('wallet.topup') && ! $user->hasPermission('wallet.approve')) {
            return response()->json([
                'error' => 'PERMISSION_DENIED',
                'message' => 'Sizda hamyon operatsiyalari va cheklarni ko\'rish uchun ruxsat (wallet.view) yo\'q.',
            ], 403);
        }

        $query = WalletTopupRequest::with(['driver.user', 'processedBy'])->latest('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $requests = $query->paginate($request->query('per_page', 25));

        $counts = [
            'total' => WalletTopupRequest::count(),
            'pending' => WalletTopupRequest::where('status', 'pending')->count(),
            'approved' => WalletTopupRequest::where('status', 'approved')->count(),
            'rejected' => WalletTopupRequest::where('status', 'rejected')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $requests->getCollection()->map(function (WalletTopupRequest $req) {
                return [
                    'id' => $req->id,
                    'driver_id' => $req->driver_id,
                    'driver_name' => $req->driver?->user?->name ?? 'Haydovchi',
                    'driver_phone' => $req->driver?->user?->phone ?? '—',
                    'driver_vehicle' => ($req->driver?->vehicle_type ?? 'Taksi') . ' • ' . ($req->driver?->license_plate ?? 'N/A'),
                    'current_balance' => (float) ($req->driver?->balance ?? 0),
                    'amount' => (float) $req->amount,
                    'amount_formatted' => number_format($req->amount, 0, '.', ' ') . " so'm",
                    'screenshot_url' => $req->screenshot_path ? url($req->screenshot_path) : null,
                    'card_number' => $req->card_number,
                    'notes' => $req->notes,
                    'status' => $req->status,
                    'source' => $req->source ?? 'mobile_app',
                    'telegram_username' => $req->telegram_username,
                    'telegram_chat_id' => $req->telegram_chat_id,
                    'processed_by' => $req->processedBy?->name,
                    'processed_at' => $req->processed_at?->format('d.m.Y H:i'),
                    'rejection_reason' => $req->rejection_reason,
                    'created_at' => $req->created_at?->format('d.m.Y H:i'),
                ];
            }),
            'counts' => $counts,
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ],
            'can_approve' => $user->hasPermission('wallet.approve') || $user->hasPermission('wallet.topup'),
            'can_reject' => $user->hasPermission('wallet.reject') || $user->hasPermission('wallet.approve'),
        ]);
    }

    /**
     * Approve top-up request and deliver funds to driver balance.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // Permission check
        if (! $user->hasPermission('wallet.approve') && ! $user->hasPermission('wallet.topup')) {
            return response()->json([
                'error' => 'PERMISSION_DENIED',
                'message' => 'Sizda to\'lov chekini tasdiqlash va balansga o\'tkazish (wallet.approve) ruxsati yo\'q.',
            ], 403);
        }

        $topupRequest = WalletTopupRequest::with('driver.user')->findOrFail($id);

        if ($topupRequest->status !== 'pending') {
            return response()->json([
                'error' => 'REQUEST_NOT_PENDING',
                'message' => "Ushbu so'rov allaqachon {$topupRequest->status} holatida.",
            ], 422);
        }

        $result = DB::transaction(function () use ($topupRequest, $user) {
            $driver = DriverProfile::where('id', $topupRequest->driver_id)->lockForUpdate()->firstOrFail();

            $newBalance = round($driver->balance + $topupRequest->amount, 2);
            $driver->balance = $newBalance;
            $driver->save();

            $topupRequest->status = 'approved';
            $topupRequest->processed_by_user_id = $user->id;
            $topupRequest->processed_at = now();
            $topupRequest->save();

            $tx = DriverWalletTransaction::create([
                'driver_id' => $driver->id,
                'order_id' => null,
                'type' => 'deposit',
                'amount' => $topupRequest->amount,
                'balance_after' => $newBalance,
                'description' => "Karta orqali to'ldirildi (Chek #{$topupRequest->id} tasdiqlandi)",
                'payment_method' => 'card',
            ]);

            return [
                'driver' => $driver,
                'transaction' => $tx,
                'topup_request' => $topupRequest,
            ];
        });

        // Notify driver via Telegram bot if telegram chat is available
        app(\App\Services\TelegramService::class)->notifyDriverApproved($topupRequest, (float) $result['driver']->balance);

        return response()->json([
            'success' => true,
            'message' => 'To\'lov cheki tasdiqlandi va mablag\' haydovchi hamyoniga yetkazildi!',
            'driver_id' => $result['driver']->id,
            'driver_name' => $topupRequest->driver?->user?->name,
            'delivered_amount' => (float) $topupRequest->amount,
            'new_balance' => (float) $result['driver']->balance,
            'new_balance_formatted' => number_format($result['driver']->balance, 0, '.', ' ') . " so'm",
            'transaction_id' => $result['transaction']->id,
        ]);
    }

    /**
     * Reject top-up request with explanation.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // Permission check
        if (! $user->hasPermission('wallet.reject') && ! $user->hasPermission('wallet.approve') && ! $user->hasPermission('wallet.topup')) {
            return response()->json([
                'error' => 'PERMISSION_DENIED',
                'message' => 'Sizda to\'lov chekini rad etish (wallet.reject) ruxsati yo\'q.',
            ], 403);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $topupRequest = WalletTopupRequest::findOrFail($id);

        if ($topupRequest->status !== 'pending') {
            return response()->json([
                'error' => 'REQUEST_NOT_PENDING',
                'message' => "Ushbu so'rov allaqachon {$topupRequest->status} holatida.",
            ], 422);
        }

        $topupRequest->status = 'rejected';
        $topupRequest->rejection_reason = $validated['reason'] ?? 'To\'lov cheki tasdiqlanmadi yoki summa mos kelmadi';
        $topupRequest->processed_by_user_id = $user->id;
        $topupRequest->processed_at = now();
        $topupRequest->save();

        // Notify driver via Telegram bot about rejection
        app(\App\Services\TelegramService::class)->notifyDriverRejected($topupRequest, $topupRequest->rejection_reason);

        return response()->json([
            'success' => true,
            'message' => 'To\'lov cheki rad etildi.',
            'request_id' => $topupRequest->id,
            'status' => 'rejected',
            'reason' => $topupRequest->rejection_reason,
        ]);
    }

    /**
     * Direct manual balance delivery by dispatcher (with screenshot or card details).
     */
    public function directDelivery(Request $request): JsonResponse
    {
        $user = $request->user();

        // Permission check
        if (! $user->hasPermission('wallet.topup')) {
            return response()->json([
                'error' => 'PERMISSION_DENIED',
                'message' => 'Sizda haydovchi balansini to\'ldirish uchun ruxsat (wallet.topup) yo\'q.',
            ], 403);
        }

        $validated = $request->validate([
            'driver_id' => ['required', 'exists:driver_profiles,id'],
            'amount' => ['required', 'numeric', 'min:1000', 'max:50000000'],
            'card_number' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:255'],
            'screenshot' => ['nullable', 'file', 'image', 'max:10240'],
            'screenshot_url' => ['nullable', 'string'],
        ]);

        $driver = DriverProfile::with('user')->findOrFail($validated['driver_id']);
        $amount = (float) $validated['amount'];

        $screenshotPath = $validated['screenshot_url'] ?? null;
        if ($request->hasFile('screenshot')) {
            $file = $request->file('screenshot');
            $filename = 'receipt_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('topup_receipts', $filename, 'public');
            $screenshotPath = '/storage/' . $path;
        }

        $result = DB::transaction(function () use ($driver, $amount, $validated, $screenshotPath, $user) {
            $lockedDriver = DriverProfile::where('id', $driver->id)->lockForUpdate()->firstOrFail();
            $newBalance = round($lockedDriver->balance + $amount, 2);
            $lockedDriver->balance = $newBalance;
            $lockedDriver->save();

            $topupReq = WalletTopupRequest::create([
                'driver_id' => $driver->id,
                'user_id' => $driver->user_id,
                'amount' => $amount,
                'screenshot_path' => $screenshotPath,
                'card_number' => $validated['card_number'] ?? null,
                'notes' => $validated['notes'] ?? "Dispetcher tomonidan to'g'ridan-to'g'ri yetkazildi",
                'status' => 'approved',
                'processed_by_user_id' => $user->id,
                'processed_at' => now(),
            ]);

            $tx = DriverWalletTransaction::create([
                'driver_id' => $driver->id,
                'order_id' => null,
                'type' => 'deposit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'description' => "Dispetcher yetkazdi: " . ($validated['notes'] ?: "Karta to'lovi"),
                'payment_method' => 'dispatcher',
            ]);

            return [
                'driver' => $lockedDriver,
                'topup_request' => $topupReq,
                'transaction' => $tx,
            ];
        });

        // Notify driver via Telegram
        app(\App\Services\TelegramService::class)->notifyDriverApproved($result['topup_request'], (float) $result['driver']->balance);

        return response()->json([
            'success' => true,
            'message' => "Mablag' muvaffaqiyatli yetkazildi!",
            'driver_id' => $driver->id,
            'driver_name' => $driver->user?->name,
            'delivered_amount' => $amount,
            'new_balance' => (float) $result['driver']->balance,
            'new_balance_formatted' => number_format($result['driver']->balance, 0, '.', ' ') . " so'm",
            'transaction_id' => $result['transaction']->id,
            'request_id' => $result['topup_request']->id,
        ]);
    }
}
