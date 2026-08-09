<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'lead_id', 'lead_event_id', 'user_id', 'rating', 'correction', 'synced_at'])]
class AiFeedback extends Model
{
    use BelongsToAccount, HasUuids;

    protected $table = 'ai_feedback';

    public const UP = 'up';

    public const DOWN = 'down';

    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(LeadEvent::class, 'lead_event_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
