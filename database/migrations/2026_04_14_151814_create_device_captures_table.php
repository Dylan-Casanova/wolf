<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('device_captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('geo_fence_id')->nullable()->constrained('geo_fences')->nullOnDelete();
            $table->string('trigger_source')->default('manual'); // 'manual' | 'geo_fence'
            $table->string('media_type')->default('image');      // 'image' | 'video'
            $table->string('media_url')->nullable();
            $table->string('media_path')->nullable();
            $table->string('status')->default('pending');        // 'pending' | 'success' | 'failed'
            $table->text('error_message')->nullable();
            $table->json('device_meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_captures');
    }
};
