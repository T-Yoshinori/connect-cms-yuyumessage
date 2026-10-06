<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class YuyuMessagePluginSeeder extends Seeder
{
    public function run()
    {
        if (!DB::table('plugins')->whereRaw('LOWER(plugin_name) = ?', ['yuyumessage'])->exists()) {
            DB::table('plugins')->insert([
                'plugin_name' => 'yuyumessage', 'plugin_name_full' => 'YuyuMessage',
                'display_flag' => 1, 'display_sequence' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}

