<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FoodAlias extends Model
{
    protected $fillable = ['alimento_id', 'alias', 'normalized'];
}
