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
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 64)->nullable()->index()->after('phone');
            $table->string('telegram_username', 128)->nullable()->after('telegram_chat_id');
        });

        Schema::table('wallet_topup_requests', function (Blueprint $table) {
            $table->string('source', 32)->default('mobile_app')->after('status'); // 'telegram', 'mobile_app', 'dispatcher'
            $table->string('telegram_chat_id', 64)->nullable()->index()->after('source');
            $table->string('telegram_message_id', 64)->nullable()->after('telegram_chat_id');
            $table->string('telegram_username', 128)->nullable()->after('telegram_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallet_topup_requests', function (Blueprint $table) {
            $table->dropColumn(['source', 'telegram_chat_id', 'telegram_message_id', 'telegram_username']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'telegram_username']);
        });
    }
};
