<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_fences', function (Blueprint $table) {
            $table->boolean('live_check_armed')->default(false)->after('west_lng');
        });

        // Best-effort carryover of existing is_active values. Today's is_active is
        // dominantly written by the web's scheduled-trigger path, so this MIGHT
        // set live_check_armed=true for fences that only have a pending timer.
        // Acceptable in dev/staging; production should run this only after
        // confirming `SELECT COUNT(*) FROM scheduled_geofence_triggers
        // WHERE status='pending'` returns 0.
        DB::statement('UPDATE geo_fences SET live_check_armed = is_active');

        Schema::table('geo_fences', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('geo_fences', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->after('west_lng');
        });
        DB::statement('UPDATE geo_fences SET is_active = live_check_armed');
        Schema::table('geo_fences', function (Blueprint $table) {
            $table->dropColumn('live_check_armed');
        });
    }
};
