<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFood extends Model
{
    protected $table = 'user_foods';

    protected $fillable = ['user_id', 'food_id', 'is_favorite'];

    protected function casts(): array
    {
        return ['is_favorite' => 'boolean'];
    }
}
