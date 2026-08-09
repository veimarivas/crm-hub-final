<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['account_id', 'user_id', 'widget_key', 'position', 'size', 'config', 'is_visible'])]
class DashboardWidget extends Model
{
    use BelongsToAccount, HasUuids;

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_visible' => 'boolean',
        ];
    }
}
