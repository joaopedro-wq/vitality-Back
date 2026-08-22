<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\MealPresetService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            return;
        }

        $superAdmin = User::updateOrCreate(['email' => $email], [
            'name' => env('ADMIN_NAME', 'João Pedro'),
            'password' => Hash::make($password, ['rounds' => 12]),
            'is_admin' => true,
        ]);

        $superAdmin->update(['is_admin' => true]);
        app(MealPresetService::class)->ensureFor($superAdmin->fresh());
    }
}
