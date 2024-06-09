<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Dieta extends Model
{
    use HasFactory;

    protected $fillable = [
        'descricao',
        'id_refeicao',
       

    ];


    public function alimentos()
    {
        return $this->belongsToMany(Alimento::class, 'dieta_alimentos', 'dieta_id', 'alimento_id')->withPivot('qtd');
    }

    public function refeicao()
    {
        return $this->belongsTo(Refeicao::class, 'id_refeicao');
    }

    public function alimento()
    {
        return $this->belongsTo(Alimento::class, 'id_alimento');
    }

   
}
