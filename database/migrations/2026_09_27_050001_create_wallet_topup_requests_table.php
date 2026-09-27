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
        Schema::create('wallet_topup_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('driver_profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('screenshot_path', 512)->nullable(); // Screenshot/receipt image path
            $table->string('card_number', 64)->nullable(); // Admin card or sender card
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('pending'); // 'pending', 'approved', 'rejected'
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_topup_requests');
    }
};
