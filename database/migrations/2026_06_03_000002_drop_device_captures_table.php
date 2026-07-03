<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('device_captures');
    }

    public function down(): void
    {
        // Not reversible — captures are ephemeral now
    }
};
