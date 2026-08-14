<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\MealPresetService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    
    public function run(): void
    {
        $superAdmin = User::firstOrCreate(['email' => 'joao.bandeira@gmail.com.br'], [
                'name' => 'João Pedro',
                'email' => 'joao.bandeira@gmail.com.br',
                'password' => Hash::make('12345678a', ['rounds' => 12]),
                'is_admin' => true,
        ]);

        $superAdmin->update(['is_admin' => true]);
        app(MealPresetService::class)->ensureFor($superAdmin->fresh());
    }
}
