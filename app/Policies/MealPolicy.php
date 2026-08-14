<?php

namespace App\Policies;

use App\Models\Refeicao;
use App\Models\User;

class MealPolicy
{
    public function view(User $user, Refeicao $meal): bool
    {
        return $meal->id_usuario === $user->id;
    }

    public function update(User $user, Refeicao $meal): bool
    {
        return $this->view($user, $meal);
    }

    public function delete(User $user, Refeicao $meal): bool
    {
        return $this->view($user, $meal);
    }
}
