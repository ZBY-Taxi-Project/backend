<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTopupRequest extends Model
{
    use HasFactory;

    protected $table = 'wallet_topup_requests';

    protected $fillable = [
        'driver_id',
        'user_id',
        'amount',
        'screenshot_path',
        'card_number',
        'notes',
        'status',
        'source',
        'telegram_chat_id',
        'telegram_message_id',
        'telegram_username',
        'processed_by_user_id',
        'processed_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'processed_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }
}
