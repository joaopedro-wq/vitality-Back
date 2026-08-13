<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlimentoNutriente extends Model
{
    protected $table = 'alimento_nutrientes';
    protected $fillable = ['alimento_id', 'nutriente_id', 'valor', 'tipo_dado'];
}
