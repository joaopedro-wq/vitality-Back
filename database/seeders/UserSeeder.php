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
        $email = config('admin.email');
        $password = config('admin.password');

        if (! $email || ! $password) {
            return;
        }

        $superAdmin = User::updateOrCreate(['email' => $email], [
            'name' => config('admin.name'),
            'password' => Hash::make($password, ['rounds' => 12]),
            'is_admin' => true,
        ]);

        $superAdmin->update(['is_admin' => true]);
        app(MealPresetService::class)->ensureFor($superAdmin->fresh());
    }
}
