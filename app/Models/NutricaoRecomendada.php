<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NutricaoRecomendada extends Model
{
    use HasFactory;
    protected $fillable = [
        'get',
        'tmb',
        'caloria',
        'proteina',
        'carbo',
        'id_usuario',


    ];


    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}
