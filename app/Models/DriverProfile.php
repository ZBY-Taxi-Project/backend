<?php

namespace App\Models;

use App\Enums\DriverStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriverProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vehicle_type',
        'license_plate',
        'status',
        'total_distance_km',
        'balance',
        'rating',
        'current_lat',
        'current_lng',
        'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
            'total_distance_km' => 'float',
            'balance' => 'float',
            'rating' => 'float',
            'current_lat' => 'float',
            'current_lng' => 'float',
            'last_active_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class, 'driver_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'current_driver_id');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(DriverWalletTransaction::class, 'driver_id')->orderBy('created_at', 'desc');
    }

    public function isAvailable(): bool
    {
        return $this->status === DriverStatus::AVAILABLE;
    }
}
