<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GroupService;
use Illuminate\Console\Command;


class BackfillGlobalGroup extends Command
{
    protected $signature = 'groups:backfill-global';
    protected $description = 'Coloca todas as contas existentes no grupo global Vitality';

    public function handle(): int
    {
        $total = 0;
        User::query()->each(function (User $user) use (&$total) {
            GroupService::ensureGlobalMembership($user);
            $total++;
        });

        $this->info("{$total} conta(s) associada(s) ao grupo Vitality.");

        return self::SUCCESS;
    }
}
