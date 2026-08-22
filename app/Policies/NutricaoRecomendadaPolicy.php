<?php

namespace App\Policies;

use App\Models\NutricaoRecomendada;
use App\Models\User;

class NutricaoRecomendadaPolicy
{
    public function view(User $user, NutricaoRecomendada $recomendacao): bool
    {
        return $recomendacao->id_usuario === $user->id;
    }

    public function update(User $user, NutricaoRecomendada $recomendacao): bool
    {
        return $this->view($user, $recomendacao);
    }

    public function delete(User $user, NutricaoRecomendada $recomendacao): bool
    {
        return $this->view($user, $recomendacao);
    }
}
