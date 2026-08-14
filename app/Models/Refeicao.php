<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Refeicao extends Model
{
    use HasFactory;
    protected $fillable = [
        'descricao',
        'horario',
        'id_usuario',
        'chave_padrao',
        'ordem',
        'archived_at',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }   

    public function registros()
    {
        return $this->hasMany(Registro::class, 'id_refeicao');
    }

    protected function casts(): array
    {
        return [
            'archived_at' => 'immutable_datetime',
            'ordem' => 'integer',
        ];
    }
}
