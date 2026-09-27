<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverWalletTransaction extends Model
{
    use HasFactory;

    protected $table = 'driver_wallet_transactions';

    protected $fillable = [
        'driver_id',
        'order_id',
        'type',
        'amount',
        'balance_after',
        'description',
        'payment_method',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'balance_after' => 'float',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
