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
        // Add commission tracking to orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->default(10.00)->after('base_fare'); // 10%
            $table->decimal('commission_amount', 10, 2)->default(0)->after('commission_rate');
        });

        // Add wallet balance and rating to driver_profiles table
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->decimal('balance', 14, 2)->default(200000.00)->after('total_distance_km'); // Default starting balance
            $table->decimal('rating', 3, 1)->default(4.9)->after('balance');
        });

        // Create driver_wallet_transactions table
        Schema::create('driver_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('driver_profiles')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('type', 32); // 'commission', 'deposit', 'refund', 'adjustment'
            $table->decimal('amount', 12, 2); // negative for commission, positive for deposit/refund
            $table->decimal('balance_after', 14, 2);
            $table->string('description', 255);
            $table->string('payment_method', 32)->nullable(); // 'click', 'payme', 'cash', 'system'
            $table->timestamps();

            $table->index(['driver_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_wallet_transactions');

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['balance', 'rating']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'commission_amount']);
        });
    }
};
