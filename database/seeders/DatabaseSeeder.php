<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
    	   \Database\Seeders\UserSeeder::class,
           \Database\Seeders\NotificationSeeder::class,
	]);
    }
}