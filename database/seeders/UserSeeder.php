<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class UserSeeder extends Seeder
{
    public function run()
    {
        User::create([
            'name' => 'Иван Петров',
            'email' => 'ivan@example.com',
        ]);

        User::create([
            'name' => 'Мария Сидорова',
            'email' => 'maria@example.com',
        ]);

        User::create([
            'name' => 'Алексей Козлов',
            'email' => 'goga93571@gmail.com',
        ]);

        User::create([
            'name' => 'Елена Волкова',
            'email' => 'elena@example.com',
        ]);

        User::create([
            'name' => 'Дмитрий Соколов',
            'email' => 'dmitry@example.com',
        ]);
    }
}