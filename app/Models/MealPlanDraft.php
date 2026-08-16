<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlanDraft extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'user_id', 'provider', 'model', 'preferences', 'payload', 'previous_payload', 'expires_at'];

    protected function casts(): array
    {
        return ['preferences' => 'array', 'payload' => 'array', 'previous_payload' => 'array', 'expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
