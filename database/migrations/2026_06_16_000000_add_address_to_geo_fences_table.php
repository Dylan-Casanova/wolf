<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_fences', function (Blueprint $table) {
            // The address point selected by the user during geofence creation.
            // Nullable so existing geofences (created before this column existed)
            // don't break; for new fences it's populated by AddressSearch.
            $table->decimal('address_lat', 10, 7)->nullable()->after('west_lng');
            $table->decimal('address_lng', 10, 7)->nullable()->after('address_lat');
        });
    }

    public function down(): void
    {
        Schema::table('geo_fences', function (Blueprint $table) {
            $table->dropColumn(['address_lat', 'address_lng']);
        });
    }
};
