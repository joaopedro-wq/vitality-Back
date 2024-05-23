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
        'id_alimento',
        'id_refeicao',

    ];

    public function alimento()
    {
        return $this->belongsTo(Alimento::class, 'id_alimento');
    }

    public function refeicao()
    {
        return $this->belongsTo(Refeicao::class, 'id_refeicao');
    }
}
