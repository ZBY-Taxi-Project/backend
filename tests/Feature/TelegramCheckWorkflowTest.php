<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Action;
use App\Models\DriverProfile;
use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use App\Models\WalletTopupRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramCheckWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $driverUser;
    protected DriverProfile $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'name' => 'Admin User',
            'phone' => '+998901112233',
        ]);

        $this->driverUser = User::factory()->create([
            'role' => UserRole::DRIVER,
            'name' => 'Rustam Haydovchi',
            'phone' => '+998909876543',
            'telegram_chat_id' => '123456789',
            'telegram_username' => 'rustam_taxi',
        ]);

        $this->driver = DriverProfile::factory()->create([
            'user_id' => $this->driverUser->id,
            'balance' => 50000,
            'vehicle_type' => 'Cobalt',
            'license_plate' => '01A123BC',
        ]);

        // Seed wallet permissions
        $mod = Module::create(['name' => 'wallet', 'display_name' => 'Hamyon']);
        $actView = Action::create(['name' => 'view', 'display_name' => 'Ko\'rish']);
        $actApprove = Action::create(['name' => 'approve', 'display_name' => 'Tasdiqlash']);
        $actReject = Action::create(['name' => 'reject', 'display_name' => 'Rad etish']);

        Permission::create(['module_id' => $mod->id, 'action_id' => $actView->id, 'code' => 'wallet.view', 'display_name' => 'Hamyon ko\'rish']);
        Permission::create(['module_id' => $mod->id, 'action_id' => $actApprove->id, 'code' => 'wallet.approve', 'display_name' => 'Chek tasdiqlash']);
        Permission::create(['module_id' => $mod->id, 'action_id' => $actReject->id, 'code' => 'wallet.reject', 'display_name' => 'Chek rad etish']);
    }

    public function test_get_telegram_bot_info(): void
    {
        $res = $this->getJson('/api/telegram/bot-info');
        $res->assertOk();
        $res->assertJsonStructure(['success', 'is_configured', 'bot_username', 'admin_card', 'webhook_url']);
    }

    public function test_telegram_webhook_start_command(): void
    {
        $payload = [
            'update_id' => 10001,
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 123456789],
                'text' => '/start',
                'from' => ['username' => 'rustam_taxi'],
            ],
        ];

        $res = $this->postJson('/api/telegram/webhook', $payload);
        $res->assertOk();
        $res->assertJson(['ok' => true]);
    }

    public function test_telegram_webhook_contact_linking(): void
    {
        $unlinkedUser = User::factory()->create([
            'role' => UserRole::DRIVER,
            'phone' => '+998935551122',
        ]);
        DriverProfile::factory()->create(['user_id' => $unlinkedUser->id]);

        $payload = [
            'update_id' => 10002,
            'message' => [
                'message_id' => 2,
                'chat' => ['id' => 987654321],
                'contact' => [
                    'phone_number' => '+998935551122',
                    'first_name' => 'New Driver',
                ],
                'from' => ['username' => 'new_driver'],
            ],
        ];

        $res = $this->postJson('/api/telegram/webhook', $payload);
        $res->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $unlinkedUser->id,
            'telegram_chat_id' => '987654321',
            'telegram_username' => 'new_driver',
        ]);
    }

    public function test_telegram_webhook_receives_photo_check(): void
    {
        $payload = [
            'update_id' => 10003,
            'message' => [
                'message_id' => 3,
                'chat' => ['id' => 123456789],
                'caption' => '100000 Click orqali o\'tkazildi',
                'photo' => [
                    ['file_id' => 'thumb123', 'file_size' => 1000],
                    ['file_id' => 'highres123', 'file_size' => 50000],
                ],
                'from' => ['username' => 'rustam_taxi'],
            ],
        ];

        $res = $this->postJson('/api/telegram/webhook', $payload);
        $res->assertOk();

        $this->assertDatabaseHas('wallet_topup_requests', [
            'driver_id' => $this->driver->id,
            'amount' => 100000,
            'source' => 'telegram',
            'telegram_chat_id' => '123456789',
            'status' => 'pending',
        ]);
    }

    public function test_simulate_incoming_telegram_check(): void
    {
        $res = $this->postJson('/api/telegram/simulate-incoming-check', [
            'driver_id' => $this->driver->id,
            'amount' => 150000,
            'caption' => 'Payme orqali adminga to\'landim',
            'telegram_username' => 'rustam_driver',
            'chat_id' => '123456789',
        ]);

        $res->assertCreated();
        $res->assertJson([
            'success' => true,
            'request' => [
                'driver_id' => $this->driver->id,
                'amount' => 150000,
                'source' => 'telegram',
                'status' => 'pending',
            ],
        ]);

        $this->assertDatabaseHas('wallet_topup_requests', [
            'driver_id' => $this->driver->id,
            'amount' => 150000,
            'source' => 'telegram',
            'status' => 'pending',
        ]);
    }

    public function test_dispatcher_approves_telegram_check_and_delivers_money(): void
    {
        $topupReq = WalletTopupRequest::create([
            'driver_id' => $this->driver->id,
            'user_id' => $this->driverUser->id,
            'amount' => 100000,
            'screenshot_path' => '/storage/topup_receipts/sample_receipt.png',
            'status' => 'pending',
            'source' => 'telegram',
            'telegram_chat_id' => '123456789',
            'telegram_username' => 'rustam_taxi',
        ]);

        $token = $this->admin->createToken('admin-test')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/dispatcher/wallet/topup-requests/{$topupReq->id}/approve");

        $res->assertOk();
        $res->assertJson([
            'success' => true,
            'delivered_amount' => 100000,
            'new_balance' => 150000,
        ]);

        $this->assertDatabaseHas('driver_profiles', [
            'id' => $this->driver->id,
            'balance' => 150000,
        ]);

        $this->assertDatabaseHas('wallet_topup_requests', [
            'id' => $topupReq->id,
            'status' => 'approved',
            'processed_by_user_id' => $this->admin->id,
        ]);
    }

    public function test_dispatcher_rejects_telegram_check(): void
    {
        $topupReq = WalletTopupRequest::create([
            'driver_id' => $this->driver->id,
            'user_id' => $this->driverUser->id,
            'amount' => 100000,
            'status' => 'pending',
            'source' => 'telegram',
            'telegram_chat_id' => '123456789',
        ]);

        $token = $this->admin->createToken('admin-test')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/dispatcher/wallet/topup-requests/{$topupReq->id}/reject", [
                'reason' => 'Chek fotosi aniq ko\'rinmadi, summa kelib tushmadi',
            ]);

        $res->assertOk();
        $res->assertJson(['status' => 'rejected']);

        $this->assertDatabaseHas('wallet_topup_requests', [
            'id' => $topupReq->id,
            'status' => 'rejected',
            'rejection_reason' => 'Chek fotosi aniq ko\'rinmadi, summa kelib tushmadi',
        ]);
    }
}
