<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FoodPlanTag extends Model
{
    protected $fillable = ['slug', 'label'];
    public function foods(): BelongsToMany { return $this->belongsToMany(Alimento::class, 'alimento_food_plan_tag'); }
}
