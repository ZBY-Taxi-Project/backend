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
        // 1. Modules table (modullar)
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique(); // 'wallet', 'orders', 'drivers', 'map', 'settings'
            $table->string('display_name', 128); // 'Hamyon va Moliya', 'Buyurtmalar'
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 2. Actions table (amallar)
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique(); // 'view', 'create', 'edit', 'delete', 'topup', 'approve', 'reject'
            $table->string('display_name', 128); // 'Ko\'rish', 'Balans to\'ldirish', 'Tasdiqlash'
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 3. Permissions table (modul va amallarni birlashtiruvchi ruxsatlar)
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->foreignId('action_id')->constrained('actions')->cascadeOnDelete();
            $table->string('code', 128)->unique(); // 'wallet.topup', 'wallet.approve', 'orders.create'
            $table->string('display_name', 128);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['module_id', 'action_id']);
        });

        // 4. User permissions table (foydalanuvchilarga berilgan ruxsatlar)
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'permission_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('actions');
        Schema::dropIfExists('modules');
    }
};
