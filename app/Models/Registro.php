<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Registro extends Model
{
    use HasFactory;

    protected $fillable = [
        'descricao',
        'data',
        'qtd',
        'id_alimento',
    ];
    public function alimento()
    {
        return $this->belongsTo(Alimento::class, 'id_alimento');
    }
}
