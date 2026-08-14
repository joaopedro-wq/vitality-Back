<?php

namespace App\Policies;

use App\Models\Registro;
use App\Models\User;

class DiaryEntryPolicy
{
    public function view(User $user, Registro $entry): bool
    {
        return $entry->id_usuario === $user->id;
    }

    public function update(User $user, Registro $entry): bool
    {
        return $this->view($user, $entry);
    }

    public function delete(User $user, Registro $entry): bool
    {
        return $this->view($user, $entry);
    }
}
