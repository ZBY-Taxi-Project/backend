<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'phone',
        'telegram_chat_id',
        'telegram_username',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    public function isDispatcher(): bool
    {
        return $this->role === UserRole::DISPATCHER || $this->role === UserRole::ADMIN;
    }

    public function isDriver(): bool
    {
        return $this->role === UserRole::DRIVER;
    }

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function createdOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by_user_id');
    }

    public function dispatchedAssignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class, 'dispatcher_id');
    }

    /**
     * Predefined role-based permissions mapping.
     * Admin: Full access to manage all orders, driver info, payment status & balance delivery.
     * Dispatcher: Standard operations (view orders, create orders, assign orders, view drivers & live map, view wallet checks).
     */
    public static function getPermissionsByRole(UserRole|string $role): array
    {
        $roleValue = $role instanceof UserRole ? $role->value : $role;

        if ($roleValue === UserRole::ADMIN->value) {
            return [
                // Orders management
                'orders.view',
                'orders.create',
                'orders.edit',
                'orders.cancel',
                'orders.assign',
                // Driver info management
                'drivers.view',
                'drivers.create',
                'drivers.edit',
                'drivers.delete',
                // Payment status & balance delivery
                'wallet.view',
                'wallet.topup',
                'wallet.approve',
                'wallet.reject',
                'payments.manage',
                // Live Map & System settings
                'map.view',
                'settings.view',
                'settings.manage',
            ];
        }

        if ($roleValue === UserRole::DISPATCHER->value) {
            return [
                // Standard Dispatcher permissions (orders & live map radar)
                'orders.view',
                'orders.create',
                'orders.assign',
                'drivers.view',
                'map.view',
            ];
        }

        return [];
    }

    public function permissions(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions');
    }

    public function hasPermission(string $permissionCode): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($permissionCode, $this->getAllPermissions(), true);
    }

    public function getAllPermissions(): array
    {
        return self::getPermissionsByRole($this->role);
    }
}
