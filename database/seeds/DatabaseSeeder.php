<?php

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->call(RoleTableSeeder::class);
        $this->call(AlertTableSeeder::class);
        $this->call(PositionTableSeeder::class);
        $this->call(SettingTableSeeder::class);
    }
}
