<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Registro extends Model
{
    use HasFactory;

    protected $fillable = [
        'data',
        'id_refeicao',
        'id_usuario',
        'consumed_at',
        'descricao_refeicao_snapshot',
        'horario_refeicao_snapshot',
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
        return $this->belongsToMany(Alimento::class, 'registro_alimentos')
            ->using(DiaryEntryItem::class)
            ->withPivot([
                'qtd', 'descricao_snapshot', 'qtd_base_snapshot', 'proteina_snapshot',
                'gordura_snapshot', 'carbo_snapshot', 'caloria_snapshot', 'nutrientes_snapshot',
            ]);
    }

    protected function casts(): array
    {
        return [
            'data' => 'date:Y-m-d',
            'consumed_at' => 'immutable_datetime',
            'horario_refeicao_snapshot' => 'datetime:H:i:s',
        ];
    }
}
