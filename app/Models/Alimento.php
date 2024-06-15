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
        'id_usuario',

    ];



    public function dietas()
    {
        return $this->belongsToMany(Dieta::class, 'dieta_alimentos', 'alimento_id', 'dieta_id')->withPivot('qtd');
    }

    public function registros()
    {
        return $this->belongsToMany(Registro::class, 'registro_alimentos')->withPivot('qtd');
    }
    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

}
