<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FoodRestriction extends Model
{
    protected $fillable = ['slug', 'label', 'type'];
    public function foods(): BelongsToMany { return $this->belongsToMany(Alimento::class, 'alimento_food_restriction'); }
}
