<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * The demo seeder is development data only. Never run it against a
     * production database.
     */
    public function run(): void
    {
        $this->call([
            EventCategorySeeder::class,
            DemoSeeder::class,
        ]);
    }
}
