<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * Authenticate user and issue Sanctum token via username, phone, or email.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['nullable', 'string'],
            'username' => ['nullable', 'string'],
            'email' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $identifier = $validated['login']
            ?? $validated['username']
            ?? $validated['phone']
            ?? $validated['email']
            ?? null;

        if (! $identifier) {
            throw ValidationException::withMessages([
                'login' => ['Please provide a valid username, phone number, or email.'],
            ]);
        }

        // Clean phone digits for phone search (e.g. +998 90 777 00 01 -> +998907770001 or 998907770001)
        $cleanDigits = preg_replace('/[^\d]/', '', $identifier);

        $user = User::with('driverProfile')
            ->where(function ($query) use ($identifier, $cleanDigits) {
                $query->where('email', $identifier)
                    ->orWhere('username', $identifier)
                    ->orWhere('phone', $identifier);

                if (strlen($cleanDigits) >= 7) {
                    $query->orWhere('phone', '+'.$cleanDigits)
                        ->orWhere('phone', $cleanDigits)
                        ->orWhere('phone', 'like', '%'.$cleanDigits.'%');
                }
            })
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            $errorKey = ! empty($validated['email']) ? 'email' : (! empty($validated['phone']) ? 'phone' : 'login');
            throw ValidationException::withMessages([
                $errorKey => ['The provided credentials do not match our records.'],
                'login' => ['The provided credentials do not match our records.'],
            ]);
        }

        $deviceName = $validated['device_name'] ?? 'zby_client';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role->value,
                'phone' => $user->phone,
                'driver_profile' => $user->driverProfile,
                'permissions' => $user->getAllPermissions(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Get details of currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('driverProfile');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role->value,
                'phone' => $user->phone,
                'driver_profile' => $user->driverProfile,
                'permissions' => $user->getAllPermissions(),
            ],
        ]);
    }

    /**
     * Revoke current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
