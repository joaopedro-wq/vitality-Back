<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FoodPlanningProfile extends Model
{
    protected $fillable = ['alimento_id', 'family', 'consumption_form', 'preparation', 'direct_consumption', 'support_ingredient', 'portion_min_g', 'portion_max_g', 'portion_step_g', 'diet_compatibility', 'restriction_compatibility', 'confidence', 'review_status', 'reviewed_at'];
    protected function casts(): array { return ['direct_consumption' => 'boolean', 'support_ingredient' => 'boolean', 'diet_compatibility' => 'array', 'restriction_compatibility' => 'array', 'reviewed_at' => 'datetime']; }
}
