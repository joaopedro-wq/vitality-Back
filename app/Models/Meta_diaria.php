<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Meta_diaria extends Model
{
    use HasFactory;

    protected $fillable = [
        'data',
        'meta_calorias',
        'meta_proteinas',
        'meta_carboidratos',
        'meta_gorduras',
    ];
}
