<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_geofence_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('geo_fence_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('status', 16)->default('pending');
            $table->decimal('origin_lat', 10, 7);
            $table->decimal('origin_lng', 10, 7);
            $table->decimal('origin_distance_meters', 12, 2);
            $table->timestamps();

            $table->index(['geo_fence_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_geofence_triggers');
    }
};
