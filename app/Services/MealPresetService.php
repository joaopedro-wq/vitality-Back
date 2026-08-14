<?php

namespace App\Services;

use App\Models\Refeicao;
use App\Models\User;

class MealPresetService
{
    private const DEFAULTS = [
        'cafe_da_manha' => ['descricao' => 'Café da manhã', 'horario' => '08:00:00', 'ordem' => 1],
        'almoco' => ['descricao' => 'Almoço', 'horario' => '11:30:00', 'ordem' => 2],
        'lanche_da_tarde' => ['descricao' => 'Lanche da tarde', 'horario' => '16:00:00', 'ordem' => 3],
        'jantar' => ['descricao' => 'Jantar', 'horario' => '20:00:00', 'ordem' => 4],
        'ceia' => ['descricao' => 'Ceia', 'horario' => '22:00:00', 'ordem' => 5],
    ];

    public function ensureFor(User $user): void
    {
        foreach (self::DEFAULTS as $key => $meal) {
            Refeicao::firstOrCreate(
                ['id_usuario' => $user->id, 'chave_padrao' => $key],
                $meal,
            );
        }
    }
}
