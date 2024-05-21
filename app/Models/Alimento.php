<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Alimento extends Model
{
    use HasFactory;

    protected $fillable = [
        'descricao',
        'proteina',
        'gordura',
        'caloria',
        'carbo',
        'qtd',
    ];
}