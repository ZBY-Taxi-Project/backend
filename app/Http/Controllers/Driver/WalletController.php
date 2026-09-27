<?php

namespace App\Http\Controllers\Driver;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\DriverWalletTransaction;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    /**
     * Format currency in Uzbek Som: 214570 -> "214 570 so'm"
     */
    private function formatSom(float|int $amount, bool $showPlus = false): string
    {
        $abs = abs($amount);
        $formatted = number_format($abs, 0, '.', ' ') . " so'm";
        if ($amount < 0) {
            return '-' . $formatted;
        }
        if ($showPlus && $amount > 0) {
            return '+' . $formatted;
        }
        return $formatted;
    }

    /**
     * Get Driver Profile and Wallet details matching the Flutter design.
     */
    public function getWallet(Request $request): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driverProfile;

        if (! $driver) {
            return response()->json([
                'success' => false,
                'message' => 'Driver profile not found.',
            ], 404);
        }

        $balance = (float) ($driver->balance ?? 0);
        $isSufficient = $balance >= 10000;

        // Calculate driver totals from completed/delivered orders
        $completedOrders = Order::where('current_driver_id', $driver->id)
            ->whereIn('status', [OrderStatus::DELIVERED, OrderStatus::IN_TRANSIT, OrderStatus::PICKED_UP, OrderStatus::DRIVER_ACCEPTED])
            ->get();

        $totalRevenue = (float) $completedOrders->sum('total_amount');
        if ($totalRevenue <= 0 && $driver->walletTransactions()->where('type', 'commission')->exists()) {
            // Fallback: estimate revenue as 10x total commission
            $totalCommissionSum = abs((float) $driver->walletTransactions()->where('type', 'commission')->sum('amount'));
            $totalRevenue = $totalCommissionSum * 10;
        }

        $totalCommission = (float) abs($driver->walletTransactions()->where('type', 'commission')->sum('amount'));
        if ($totalCommission <= 0 && $totalRevenue > 0) {
            $totalCommission = round($totalRevenue * 0.10, 2);
        }

        $netEarnings = max(0, $totalRevenue - $totalCommission);

        // Weekly earnings data (Monday to Sunday)
        $startOfWeek = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $daysMap = [
            0 => ['short' => 'Du', 'name' => 'Dushanba'],
            1 => ['short' => 'Se', 'name' => 'Seshanba'],
            2 => ['short' => 'Ch', 'name' => 'Chorshanba'],
            3 => ['short' => 'Pa', 'name' => 'Payshanba'],
            4 => ['short' => 'Ju', 'name' => 'Juma'],
            5 => ['short' => 'Sh', 'name' => 'Shanba'],
            6 => ['short' => 'Ya', 'name' => 'Yakshanba'],
        ];

        $weeklyDays = [];
        $maxDailyEarnings = 1;

        for ($i = 0; $i < 7; $i++) {
            $currentDay = $startOfWeek->copy()->addDays($i);
            $dayOrders = Order::where('current_driver_id', $driver->id)
                ->whereIn('status', [OrderStatus::DELIVERED, OrderStatus::IN_TRANSIT, OrderStatus::PICKED_UP, OrderStatus::DRIVER_ACCEPTED])
                ->whereDate('created_at', $currentDay->toDateString())
                ->get();

            $dayGross = (float) $dayOrders->sum('total_amount');
            $dayCommission = round($dayGross * 0.10, 2);
            $dayNet = max(0, $dayGross - $dayCommission);

            if ($dayNet > $maxDailyEarnings) {
                $maxDailyEarnings = $dayNet;
            }

            $weeklyDays[] = [
                'day' => $daysMap[$i]['short'],
                'day_name' => $daysMap[$i]['name'],
                'date' => $currentDay->format('Y-m-d'),
                'gross_amount' => $dayGross,
                'commission_amount' => $dayCommission,
                'net_amount' => $dayNet,
                'formatted_net' => $this->formatSom($dayNet),
                'order_count' => $dayOrders->count(),
            ];
        }

        // Compute bar height percentages for UI chart
        foreach ($weeklyDays as &$dayItem) {
            if ($maxDailyEarnings > 0 && $dayItem['net_amount'] > 0) {
                $dayItem['height_percent'] = max(20, min(100, (int) round(($dayItem['net_amount'] / $maxDailyEarnings) * 100)));
            } else {
                // Default slight aesthetic bar representation if today or weekday
                $dayItem['height_percent'] = 15;
            }
        }
        unset($dayItem);

        // Recent wallet transactions
        $transactions = $driver->walletTransactions()
            ->latest('created_at')
            ->take(20)
            ->get()
            ->map(function (DriverWalletTransaction $tx) {
                $isDeduction = $tx->amount < 0;
                return [
                    'id' => $tx->id,
                    'order_id' => $tx->order_id,
                    'type' => $tx->type,
                    'title' => $tx->description,
                    'amount' => (float) $tx->amount,
                    'amount_formatted' => $this->formatSom($tx->amount, true),
                    'balance_after' => (float) $tx->balance_after,
                    'balance_after_formatted' => $this->formatSom($tx->balance_after),
                    'is_deduction' => $isDeduction,
                    'payment_method' => $tx->payment_method,
                    'date_formatted' => $tx->created_at ? $tx->created_at->format('d.m • H:i') : '',
                    'created_at' => $tx->created_at ? $tx->created_at->toIso8601String() : null,
                ];
            });

        return response()->json([
            'success' => true,
            'driver' => [
                'id' => $driver->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone ?? '+998907770003',
                'vehicle_type' => $driver->vehicle_type ?? 'Taksi',
                'license_plate' => $driver->license_plate ?? 'N/A',
                'rating' => (float) ($driver->rating ?? 4.9),
                'status' => $driver->status->value ?? 'available',
            ],
            'wallet' => [
                'balance' => $balance,
                'balance_formatted' => $this->formatSom($balance),
                'status_badge' => $isSufficient ? "Yetarli mablag'" : "Balans kam",
                'status_badge_type' => $isSufficient ? 'success' : 'warning',
                'commission_rate_percent' => 10,
                'commission_rate_formatted' => '10%',
                'info_message' => 'Har bir buyurtma uchun 10% xizmat haqi ushbu hamyondan yechiladi.',
                'server_badge' => 'Backend / Server',
            ],
            'statistics' => [
                'total_revenue' => $totalRevenue,
                'total_revenue_formatted' => $this->formatSom($totalRevenue),
                'total_commission' => $totalCommission,
                'total_commission_formatted' => '-' . $this->formatSom($totalCommission),
                'net_earnings' => $netEarnings,
                'net_earnings_formatted' => $this->formatSom($netEarnings),
            ],
            'weekly_chart' => $weeklyDays,
            'transactions' => $transactions,
            'tariff_settings' => [
                'rate_per_km' => 2000,
                'rate_per_km_formatted' => "1 km = 2 000 so'm",
                'commission_rate' => 10,
                'commission_rate_formatted' => 'Komissiya 10%',
                'server_settings_label' => 'Backend API manzilini tekshirish',
                'app_title' => 'ZBY Haydovchi Tizimi v1.0.0',
            ],
        ]);
    }

    /**
     * Top-up driver wallet balance.
     */
    public function topUp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1000', 'max:50000000'],
            'payment_method' => ['nullable', 'string', 'in:click,payme,card,cash,dispatcher,system'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $driver = $user->driverProfile;

        if (! $driver) {
            return response()->json([
                'success' => false,
                'message' => 'Driver profile not found.',
            ], 404);
        }

        $amount = (float) $validated['amount'];
        $method = $validated['payment_method'] ?? 'click';
        $methodLabel = match ($method) {
            'click' => 'Click',
            'payme' => 'Payme',
            'card' => 'Karta',
            'cash' => 'Naqd',
            'dispatcher' => 'Dispetcher',
            default => 'To\'lov tizimi',
        };

        $description = $validated['description'] ?? "Qo'lda to'ldirildi ({$methodLabel})";

        $transaction = DB::transaction(function () use ($driver, $amount, $description, $method) {
            $lockedDriver = $driver->lockForUpdate()->first();
            $newBalance = round($driver->balance + $amount, 2);
            $driver->balance = $newBalance;
            $driver->save();

            return DriverWalletTransaction::create([
                'driver_id' => $driver->id,
                'order_id' => null,
                'type' => 'deposit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'description' => $description,
                'payment_method' => $method,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Hisob muvaffaqiyatli to\'ldirildi!',
            'balance' => (float) $driver->balance,
            'balance_formatted' => $this->formatSom($driver->balance),
            'transaction' => [
                'id' => $transaction->id,
                'title' => $transaction->description,
                'amount' => (float) $transaction->amount,
                'amount_formatted' => $this->formatSom($transaction->amount, true),
                'balance_after' => (float) $transaction->balance_after,
                'date_formatted' => $transaction->created_at ? $transaction->created_at->format('d.m • H:i') : '',
            ],
        ]);
    }

    /**
     * Submit card payment topup request with screenshot proof.
     */
    public function submitTopupRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1000', 'max:50000000'],
            'screenshot' => ['nullable', 'file', 'image', 'max:10240'],
            'screenshot_url' => ['nullable', 'string'],
            'card_number' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $driver = $user->driverProfile;

        if (! $driver) {
            return response()->json([
                'success' => false,
                'message' => 'Driver profile not found.',
            ], 404);
        }

        $screenshotPath = $validated['screenshot_url'] ?? null;
        if ($request->hasFile('screenshot')) {
            $file = $request->file('screenshot');
            $filename = 'receipt_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('topup_receipts', $filename, 'public');
            $screenshotPath = '/storage/' . $path;
        }

        $topupReq = \App\Models\WalletTopupRequest::create([
            'driver_id' => $driver->id,
            'user_id' => $user->id,
            'amount' => (float) $validated['amount'],
            'screenshot_path' => $screenshotPath,
            'card_number' => $validated['card_number'] ?? null,
            'notes' => $validated['notes'] ?? "Karta orqali to'lov cheki",
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => "To'lov cheki dispetcherlik markaziga yuborildi. Dispetcher tasdiqlagach, pul balansingizga tushadi.",
            'request' => [
                'id' => $topupReq->id,
                'amount' => (float) $topupReq->amount,
                'amount_formatted' => $this->formatSom($topupReq->amount),
                'status' => 'pending',
                'screenshot_url' => $screenshotPath ? url($screenshotPath) : null,
                'created_at' => $topupReq->created_at ? $topupReq->created_at->format('d.m • H:i') : '',
            ],
        ]);
    }

    /**
     * Get transaction history with pagination.
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driverProfile;

        if (! $driver) {
            return response()->json([
                'success' => false,
                'message' => 'Driver profile not found.',
            ], 404);
        }

        $perPage = (int) $request->query('per_page', 20);
        $transactions = $driver->walletTransactions()
            ->latest('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $transactions->getCollection()->map(function (DriverWalletTransaction $tx) {
                return [
                    'id' => $tx->id,
                    'order_id' => $tx->order_id,
                    'type' => $tx->type,
                    'title' => $tx->description,
                    'amount' => (float) $tx->amount,
                    'amount_formatted' => $this->formatSom($tx->amount, true),
                    'balance_after' => (float) $tx->balance_after,
                    'balance_after_formatted' => $this->formatSom($tx->balance_after),
                    'is_deduction' => $tx->amount < 0,
                    'payment_method' => $tx->payment_method,
                    'date_formatted' => $tx->created_at ? $tx->created_at->format('d.m • H:i') : '',
                    'created_at' => $tx->created_at ? $tx->created_at->toIso8601String() : null,
                ];
            }),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }
}
