<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Registro extends Model
{
    use HasFactory;

    protected $fillable = [
        'data',
        'qtd',
        'id_refeicao',
        'id_usuario',

   
                    

    ];

    public function alimento()
    {
        return $this->belongsTo(Alimento::class, 'id_alimento');
    }

    public function refeicao()
    {
        return $this->belongsTo(Refeicao::class, 'id_refeicao');
    }

    public function dieta()
    {
        return $this->belongsTo(Dieta::class, 'id_dieta');
    }
    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function alimentos()
    {
        return $this->belongsToMany(Alimento::class, 'registro_alimentos')->withPivot('qtd');
    }
   

    
}

