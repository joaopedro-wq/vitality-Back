<?php

namespace App\Policies;

use App\Models\Dieta;
use App\Models\User;

class DietaPolicy
{
    public function view(User $user, Dieta $dieta): bool
    {
        return $dieta->id_usuario === $user->id;
    }

    public function update(User $user, Dieta $dieta): bool
    {
        return $this->view($user, $dieta);
    }

    public function delete(User $user, Dieta $dieta): bool
    {
        return $this->view($user, $dieta);
    }
}
