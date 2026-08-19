<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPlan extends Model
{
    protected $fillable = ['user_id', 'titulo', 'style', 'generation_provider', 'generation_model', 'generation_version', 'meal_count', 'preferences', 'target', 'totals', 'warning', 'archived_at', 'favorited_at'];
    protected function casts(): array { return ['preferences' => 'array', 'target' => 'array', 'totals' => 'array', 'archived_at' => 'datetime', 'favorited_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function meals(): HasMany { return $this->hasMany(MealPlanMeal::class)->orderBy('position'); }
}
