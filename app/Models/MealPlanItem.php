<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPlanItem extends Model
{
    protected $fillable = ['food_id', 'descricao_snapshot', 'quantity', 'culinary_role', 'macros'];
    protected function casts(): array { return ['macros' => 'array']; }
    public function meal(): BelongsTo { return $this->belongsTo(MealPlanMeal::class, 'meal_plan_meal_id'); }
    public function food(): BelongsTo { return $this->belongsTo(Alimento::class, 'food_id'); }
}
