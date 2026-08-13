<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeUserAdmin extends Command
{
    protected $signature = 'user:make-admin {email : E-mail da conta existente}';
    protected $description = 'Promove uma conta existente a administradora';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) { $this->error('Usuário não encontrado.'); return self::FAILURE; }
        $user->update(['is_admin' => true]);
        $this->info("{$user->email} agora é administrador.");
        return self::SUCCESS;
    }
}
