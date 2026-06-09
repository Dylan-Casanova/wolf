<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('devices')
            ->where('type', 'esp32-cam')
            ->update(['type' => 'esp32_cam']);
    }

    public function down(): void
    {
        DB::table('devices')
            ->where('type', 'esp32_cam')
            ->update(['type' => 'esp32-cam']);
    }
};
