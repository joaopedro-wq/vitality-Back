<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPlanMeal extends Model
{
    protected $fillable = ['position', 'descricao', 'horario', 'target', 'totals'];
    protected function casts(): array { return ['target' => 'array', 'totals' => 'array']; }
    public function plan(): BelongsTo { return $this->belongsTo(MealPlan::class, 'meal_plan_id'); }
    public function items(): HasMany { return $this->hasMany(MealPlanItem::class); }
}
