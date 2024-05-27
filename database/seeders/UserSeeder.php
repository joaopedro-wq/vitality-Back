<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    
    public function run(): void
    {
        if(!User::where('email', 'joao.bandeira@gmail.com.br')->first()){
            $superAdmin = User::create([
                'name' => 'João Pedro',
                'email' => 'joao.bandeira@gmail.com.br',
                'password' => Hash::make('12345678a', ['rounds' => 12])
            ]);
        }
    }
}
