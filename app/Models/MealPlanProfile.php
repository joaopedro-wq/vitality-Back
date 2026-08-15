<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlanProfile extends Model
{
    protected $fillable = ['user_id', 'diet_type', 'restriction_slugs', 'preferences'];
    protected function casts(): array { return ['restriction_slugs' => 'array', 'preferences' => 'array']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
