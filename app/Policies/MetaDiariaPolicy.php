<?php

namespace App\Policies;

use App\Models\Meta_diaria;
use App\Models\User;

class MetaDiariaPolicy
{
    public function view(User $user, Meta_diaria $meta): bool
    {
        return $meta->id_usuario === $user->id;
    }

    public function update(User $user, Meta_diaria $meta): bool
    {
        return $this->view($user, $meta);
    }

    public function delete(User $user, Meta_diaria $meta): bool
    {
        return $this->view($user, $meta);
    }
}
