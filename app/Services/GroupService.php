<?php

namespace App\Services;

use App\Models\Group;
use App\Models\User;

class GroupService
{
    public static function ensureGlobalMembership(User $user): Group
    {
        $group = Group::firstOrCreate(
            ['is_global' => true],
            ['name' => Group::NOME_GRUPO_GLOBAL, 'challenge_type' => 'all_time'],
        );

        $group->members()->syncWithoutDetaching([$user->id => ['joined_at' => now()]]);

        return $group;
    }
}
