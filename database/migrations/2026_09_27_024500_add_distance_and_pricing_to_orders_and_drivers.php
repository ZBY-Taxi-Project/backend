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
        // Add distance and pricing fields to orders
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('estimated_distance_km', 8, 3)->default(0)->after('delivery_lng');
            $table->decimal('actual_distance_km', 8, 3)->default(0)->after('estimated_distance_km');
            $table->decimal('rate_per_km', 10, 2)->default(2000)->after('actual_distance_km'); // 2,000 so'm per km
            $table->decimal('base_fare', 10, 2)->default(0)->after('rate_per_km');
        });

        // Add odometer field to driver_profiles
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->decimal('total_distance_km', 10, 3)->default(0)->after('status');
        });

        // Location logs table to record breadcrumbs and movements
        Schema::create('driver_location_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('driver_profiles')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('distance_delta_km', 8, 3)->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_location_logs');

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('total_distance_km');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'estimated_distance_km',
                'actual_distance_km',
                'rate_per_km',
                'base_fare',
            ]);
        });
    }
};
