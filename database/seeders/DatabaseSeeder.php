<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin
        User::updateOrCreate(
            ['email' => 'admin@teesik.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]
        );

        // Demo user
        User::updateOrCreate(
            ['email' => 'user@teesik.com'],
            [
                'name' => 'Demo User',
                'password' => Hash::make('password'),
                'role' => 'user',
            ]
        );
    }
}