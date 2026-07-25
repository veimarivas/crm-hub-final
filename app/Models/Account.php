<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name', 'owner_user_id', 'default_currency', 'auto_assign_leads',
    'business_hours_enabled', 'out_of_hours_reply_enabled', 'out_of_hours_message',
    'business_hours_timezone', 'business_hours_schedule',
])]
class Account extends Model
{
    use HasUuids;

    protected $casts = [
        'auto_assign_leads' => 'boolean',
        'business_hours_enabled' => 'boolean',
        'out_of_hours_reply_enabled' => 'boolean',
        'business_hours_schedule' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function integration(): HasOne
    {
        return $this->hasOne(Integration::class);
    }

    public function pipelines(): HasMany
    {
        return $this->hasMany(Pipeline::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
