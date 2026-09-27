<?php

namespace App\Http\Controllers\Dispatcher;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    /**
     * List system roles, their assigned permissions, and all dispatchers/admins.
     */
    public function index(Request $request): JsonResponse
    {
        $roles = [
            'admin' => [
                'role' => 'admin',
                'display_name' => '👑 Tizim Administratori',
                'description' => "Barcha huquqlarga ega: buyurtmalarni to'liq boshqarish, haydovchilarni qo'shish/tahrirlash, to'lov cheklarini tasdiqlash va haydovchi balansiga pul yetkazish.",
                'can_manage_orders' => true,
                'can_manage_drivers' => true,
                'can_manage_payments' => true,
                'permissions' => User::getPermissionsByRole('admin'),
            ],
            'dispatcher' => [
                'role' => 'dispatcher',
                'display_name' => '🎧 Dispetcher (Operator)',
                'description' => "Standart operatsiyalar: chaqiruvlarni qabul qilish, haydovchilarga buyurtma biriktirish, jonli radar xaritasi orqali taksilarni kuzatish.",
                'can_manage_orders' => false,
                'can_manage_drivers' => false,
                'can_manage_payments' => false,
                'permissions' => User::getPermissionsByRole('dispatcher'),
            ],
        ];

        $users = User::whereIn('role', [UserRole::DISPATCHER, UserRole::ADMIN])
            ->get()
            ->map(function (User $u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'username' => $u->username,
                    'phone' => $u->phone,
                    'role' => $u->role->value,
                    'is_admin' => $u->isAdmin(),
                    'permissions' => $u->getAllPermissions(),
                ];
            });

        return response()->json([
            'success' => true,
            'roles' => $roles,
            'users' => $users,
            'dispatchers' => $users, // Backward compatibility
            'current_user' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'role' => $request->user()->role->value,
                'is_admin' => $request->user()->isAdmin(),
                'permissions' => $request->user()->getAllPermissions(),
            ],
            'current_user_permissions' => $request->user()->getAllPermissions(),
        ]);
    }

    /**
     * Change user role between 'admin' and 'dispatcher' (Admin only).
     */
    public function updateUserRole(Request $request, int $userId): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json([
                'error' => 'PERMISSION_DENIED',
                'message' => 'Faqatgina Admin foydalanuvchilar rollarni boshqara oladi.',
            ], 403);
        }

        $targetUser = User::findOrFail($userId);

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:admin,dispatcher'],
        ]);

        $newRole = UserRole::from($validated['role']);
        $targetUser->role = $newRole;
        $targetUser->save();

        return response()->json([
            'success' => true,
            'message' => "Foydalanuvchi ({$targetUser->name}) roli '{$targetUser->role->value}' ga o'zgartirildi.",
            'user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'username' => $targetUser->username,
                'role' => $targetUser->role->value,
                'is_admin' => $targetUser->isAdmin(),
                'permissions' => $targetUser->getAllPermissions(),
            ],
        ]);
    }

    /**
     * Backward compatibility endpoint for permission sync or role update.
     */
    public function updateUserPermissions(Request $request, int $userId): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json([
                'error' => 'PERMISSION_DENIED',
                'message' => 'Faqatgina Admin foydalanuvchilar ruxsatlarni boshqara oladi.',
            ], 403);
        }

        $targetUser = User::findOrFail($userId);

        if ($request->has('role')) {
            $newRole = UserRole::tryFrom($request->input('role'));
            if ($newRole) {
                $targetUser->role = $newRole;
                $targetUser->save();
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Foydalanuvchi ({$targetUser->name}) roli va ruxsatlari yangilandi.",
            'user_id' => $targetUser->id,
            'role' => $targetUser->role->value,
            'permissions' => $targetUser->getAllPermissions(),
        ]);
    }
}
