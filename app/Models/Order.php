<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'customer_name',
        'customer_phone',
        'pickup_address',
        'pickup_lat',
        'pickup_lng',
        'delivery_address',
        'delivery_lat',
        'delivery_lng',
        'total_amount',
        'status',
        'current_driver_id',
        'created_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_amount' => 'decimal:2',
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'delivery_lat' => 'float',
            'delivery_lng' => 'float',
        ];
    }

    public function currentDriver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'current_driver_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OrderAssignment::class, 'order_id')->latest('assigned_at');
    }

    public function latestAssignment(): HasOne
    {
        return $this->hasOne(OrderAssignment::class, 'order_id')->latestOfMany('assigned_at');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id')->latest();
    }
}
