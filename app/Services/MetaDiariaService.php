<?php

namespace App\Services;

use App\Models\Meta_diaria;
use App\Models\User;

class MetaDiariaService
{
    /**
     * Resolve a meta "vigente" de um usuário — mesma convenção que hoje só existia no
     * frontend (`MetaService.save`): a meta com `data IS NULL` é a vigente; na ausência dela,
     * cai para a última criada. O backend não impede duplicatas, então centralizar essa
     * resolução aqui evita reimplementar a regra em cada lugar que precisa "a meta de hoje"
     * (Painel, e no futuro qualquer outra tela que precise da meta vigente sem repetir o quiz).
     */
    public function vigente(User $user): ?Meta_diaria
    {
        return Meta_diaria::query()
            ->where('id_usuario', $user->id)
            ->whereNull('data')
            ->latest('id')
            ->first()
            ?? Meta_diaria::query()
                ->where('id_usuario', $user->id)
                ->latest('id')
                ->first();
    }
}
