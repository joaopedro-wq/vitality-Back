<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMissionCompletion extends Model
{
    protected $fillable = ['user_id', 'mission_code', 'period_key', 'xp', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime', 'xp' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
