<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_id', 'user_id', 'name', 'message', 'media_path', 'filters', 'status',
    'total_recipients', 'sent_count', 'failed_count', 'sent_at', 'completed_at',
])]
class Broadcast extends Model
{
    use BelongsToAccount, HasUuids;

    protected $casts = [
        'filters' => 'array',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }
}
