<?php

namespace App\Http\Controllers\Dispatcher;

use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DriverController extends Controller
{
    /**
     * List all drivers with user info and active orders count.
     */
    public function index(Request $request): JsonResponse
    {
        // Auto-provision profile for any driver users created directly in users table
        $orphanedDrivers = User::where('role', \App\Enums\UserRole::DRIVER)
            ->whereDoesntHave('driverProfile')
            ->get();

        foreach ($orphanedDrivers as $driverUser) {
            DriverProfile::create([
                'user_id' => $driverUser->id,
                'vehicle_type' => 'Standard Vehicle',
                'license_plate' => 'Unassigned',
                'status' => DriverStatus::OFFLINE,
                'current_lat' => 41.3111,
                'current_lng' => 69.2405,
                'last_active_at' => now(),
            ]);
        }

        $query = DriverProfile::with('user');

        if ($request->filled('status')) {
            $status = DriverStatus::tryFrom($request->query('status'));
            if ($status) {
                $query->where('status', $status);
            }
        }

        $drivers = $query->get()->map(function (DriverProfile $driver) {
            return [
                'id' => $driver->id,
                'user_id' => $driver->user_id,
                'name' => $driver->user?->name ?? 'Driver #'.$driver->id,
                'email' => $driver->user?->email ?? '',
                'phone' => $driver->user?->phone ?? '',
                'vehicle_type' => $driver->vehicle_type,
                'license_plate' => $driver->license_plate,
                'status' => $driver->status->value,
                'balance' => (float) ($driver->balance ?? 0),
                'rating' => (float) ($driver->rating ?? 4.9),
                'current_lat' => $driver->current_lat,
                'current_lng' => $driver->current_lng,
                'last_active_at' => $driver->last_active_at,
            ];
        });

        $counts = [
            'total' => DriverProfile::count(),
            'available' => DriverProfile::where('status', DriverStatus::AVAILABLE)->count(),
            'busy' => DriverProfile::where('status', DriverStatus::BUSY)->count(),
            'offline' => DriverProfile::where('status', DriverStatus::OFFLINE)->count(),
        ];

        return response()->json([
            'drivers' => $drivers,
            'counts' => $counts,
        ]);
    }

    /**
     * Return list of drivers currently available for assignment.
     */
    public function available(): JsonResponse
    {
        $drivers = DriverProfile::with('user')
            ->where('status', DriverStatus::AVAILABLE)
            ->get()
            ->map(function (DriverProfile $driver) {
                return [
                    'id' => $driver->id,
                    'user_id' => $driver->user_id,
                    'name' => $driver->user?->name ?? 'Haydovchi',
                    'phone' => $driver->user?->phone ?? '—',
                    'vehicle_type' => $driver->vehicle_type,
                    'license_plate' => $driver->license_plate,
                    'status' => $driver->status->value,
                ];
            });

        return response()->json([
            'available_drivers' => $drivers,
        ]);
    }

    /**
     * Register a new real driver into the database.
     * Driver can immediately log in to Driver App using their Phone Number & Password.
     */
    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('drivers.create')) {
            return response()->json([
                'error' => 'PERMISSION_DENIED',
                'message' => 'Faqat administrator yangi haydovchi qo\'shishi mumkin (drivers.create ruxsati talab qilinadi).',
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50', 'unique:users,phone'],
            'username' => ['nullable', 'string', 'max:100', 'unique:users,username'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:6'],
            'vehicle_type' => ['required', 'string', 'max:100'],
            'license_plate' => ['required', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'in:available,busy,offline'],
            'current_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'current_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $cleanPhoneDigits = preg_replace('/[^\d]/', '', $validated['phone']);
        $email = $validated['email'] ?? "driver_{$cleanPhoneDigits}@zby.local";
        $username = $validated['username'] ?? "driver_{$cleanPhoneDigits}";

        $rawPassword = $validated['password'] ?? 'password';

        $user = \App\Models\User::create([
            'name' => $validated['name'],
            'username' => $username,
            'email' => $email,
            'phone' => $validated['phone'],
            'password' => \Illuminate\Support\Facades\Hash::make($rawPassword),
            'role' => \App\Enums\UserRole::DRIVER,
        ]);

        $status = isset($validated['status']) ? DriverStatus::from($validated['status']) : DriverStatus::AVAILABLE;

        $profile = DriverProfile::create([
            'user_id' => $user->id,
            'vehicle_type' => $validated['vehicle_type'],
            'license_plate' => $validated['license_plate'],
            'status' => $status,
            'current_lat' => $validated['current_lat'] ?? 41.3111,
            'current_lng' => $validated['current_lng'] ?? 69.2405,
            'last_active_at' => now(),
        ]);

        return response()->json([
            'message' => "Driver registered successfully! Driver can log in with Phone ({$user->phone}) and Password.",
            'driver' => [
                'id' => $profile->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'phone' => $user->phone,
                'vehicle_type' => $profile->vehicle_type,
                'license_plate' => $profile->license_plate,
                'status' => $profile->status->value,
                'current_lat' => $profile->current_lat,
                'current_lng' => $profile->current_lng,
                'last_active_at' => $profile->last_active_at,
            ],
            'login_credentials' => [
                'phone' => $user->phone,
                'password' => $rawPassword,
            ],
        ], 201);
    }

    /**
     * Update driver details in the database.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $profile = DriverProfile::with('user')->findOrFail($id);

        if ($request->hasAny(['name', 'phone', 'vehicle_type', 'license_plate'])) {
            if (! $request->user()->hasPermission('drivers.edit')) {
                return response()->json([
                    'error' => 'PERMISSION_DENIED',
                    'message' => 'Haydovchi ma\'lumotlarini tahrirlash uchun administrator ruxsati zarur (drivers.edit talab qilinadi).',
                ], 403);
            }
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', Rule::unique('users', 'phone')->ignore($profile->user_id)],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'license_plate' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'in:available,busy,offline'],
            'current_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'current_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $userUpdates = [];
        if (array_key_exists('name', $validated) && $validated['name'] !== null) {
            $userUpdates['name'] = $validated['name'];
        }
        if (array_key_exists('phone', $validated) && $validated['phone'] !== null) {
            $userUpdates['phone'] = $validated['phone'];
        }
        if (! empty($userUpdates) && $profile->user) {
            $profile->user->update($userUpdates);
        }

        $profileUpdates = [];
        if (isset($validated['vehicle_type'])) $profileUpdates['vehicle_type'] = $validated['vehicle_type'];
        if (isset($validated['license_plate'])) $profileUpdates['license_plate'] = $validated['license_plate'];
        if (isset($validated['status'])) $profileUpdates['status'] = DriverStatus::from($validated['status']);
        if (array_key_exists('current_lat', $validated)) $profileUpdates['current_lat'] = $validated['current_lat'];
        if (array_key_exists('current_lng', $validated)) $profileUpdates['current_lng'] = $validated['current_lng'];
        $profileUpdates['last_active_at'] = now();

        $profile->update($profileUpdates);
        $profile->refresh();

        return response()->json([
            'message' => 'Driver updated successfully in backend database',
            'driver' => [
                'id' => $profile->id,
                'user_id' => $profile->user_id,
                'name' => $profile->user?->name ?? 'Haydovchi',
                'email' => $profile->user?->email ?? '',
                'phone' => $profile->user?->phone ?? '',
                'vehicle_type' => $profile->vehicle_type,
                'license_plate' => $profile->license_plate,
                'status' => $profile->status->value,
                'balance' => (float) ($profile->balance ?? 0),
                'rating' => (float) ($profile->rating ?? 4.9),
                'current_lat' => $profile->current_lat,
                'current_lng' => $profile->current_lng,
                'last_active_at' => $profile->last_active_at,
            ],
        ]);
    }

    /**
     * Dispatcher manual wallet topup for a driver.
     */
    public function topupWallet(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->hasPermission('wallet.topup')) {
            return response()->json([
                'error' => 'PERMISSION_DENIED',
                'message' => "Sizda haydovchi balansini to'ldirish uchun ruxsat (wallet.topup) yo'q.",
            ], 403);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1000', 'max:50000000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $profile = DriverProfile::findOrFail($id);
        $amount = (float) $validated['amount'];
        $notes = $validated['notes'] ?? "Dispetcher orqali to'ldirildi";

        $newBalance = round($profile->balance + $amount, 2);
        $profile->balance = $newBalance;
        $profile->save();

        $tx = \App\Models\DriverWalletTransaction::create([
            'driver_id' => $profile->id,
            'order_id' => null,
            'type' => 'deposit',
            'amount' => $amount,
            'balance_after' => $newBalance,
            'description' => "Dispetcher to'ldirdi: {$notes}",
            'payment_method' => 'dispatcher',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Haydovchi balansi muvaffaqiyatli to\'ldirildi',
            'driver_id' => $profile->id,
            'balance' => $newBalance,
            'transaction_id' => $tx->id,
        ]);
    }

    /**
     * Delete driver from the system (Admin only).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->hasPermission('drivers.delete')) {
            return response()->json([
                'error' => 'PERMISSION_DENIED',
                'message' => 'Faqat administrator haydovchini o\'chira oladi (drivers.delete ruxsati talab qilinadi).',
            ], 403);
        }

        $profile = DriverProfile::with('user')->findOrFail($id);

        if ($profile->status === DriverStatus::BUSY) {
            return response()->json([
                'error' => 'CANNOT_DELETE_ACTIVE_DRIVER',
                'message' => 'Aktiv safarda bo\'lgan haydovchini o\'chirib bo\'lmaydi. Avval safarni yakunlang yoki bekor qiling.',
            ], 422);
        }

        $driverName = $profile->user?->name ?? "Haydovchi #{$profile->id}";
        $user = $profile->user;

        // Delete driver profile
        $profile->delete();

        // Delete associated user record if driver role
        if ($user && $user->role === \App\Enums\UserRole::DRIVER) {
            $user->tokens()->delete();
            $user->delete();
        }

        return response()->json([
            'success' => true,
            'message' => "Haydovchi ({$driverName}) tizimdan muvaffaqiyatli o'chirildi.",
        ]);
    }
}
