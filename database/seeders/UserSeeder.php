<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class UserSeeder extends Seeder
{
    public function run()
    {
        User::create([
            'name' => 'gen-ini',
            'email' => 'ivan@example.com',
        ]);

        User::create([
            'name' => 'mar-sid',
            'email' => 'maria@example.com',
        ]);

        User::create([
            'name' => 'test-goga',
            'email' => 'goga93571@gmail.com',
        ]);

        User::create([
            'name' => 'el-vo',
            'email' => 'elena@example.com',
        ]);

        User::create([
            'name' => 'dmit-orl',
            'email' => 'dmitry@example.com',
        ]);
    }
}