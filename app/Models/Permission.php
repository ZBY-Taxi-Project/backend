<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'module_id',
        'action_id',
        'code',
        'display_name',
        'description',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permissions');
    }
}
