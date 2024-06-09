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


  /*   public function dietas()
    {
        return $this->belongsToMany(Dieta::class, 'dieta_alimento', 'alimento_id', 'dieta_id');
        
    } */

    public function dietas()
    {
        return $this->belongsToMany(Dieta::class, 'dieta_alimentos', 'alimento_id', 'dieta_id')->withPivot('qtd');
    }
}
