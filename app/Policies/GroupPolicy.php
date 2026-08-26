<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function view(User $user, Group $group): bool
    {
        return $group->members()->where('user_id', $user->id)->exists();
    }

    public function delete(User $user, Group $group): bool
    {

        if ($group->is_global) {
            return false;
        }

        return $group->owner_id === $user->id;
    }
}
