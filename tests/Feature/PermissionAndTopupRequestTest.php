<?php

namespace Tests\Feature;

use App\Enums\DriverStatus;
use App\Enums\UserRole;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\WalletTopupRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PermissionAndTopupRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $dispatcher;
    private User $driverUser;
    private DriverProfile $driverProfile;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        // Admin User (Full Access)
        $this->admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'name' => 'Super Administrator',
            'phone' => '+998901112233',
        ]);

        // Regular Dispatcher (Restricted Operations)
        $this->dispatcher = User::factory()->create([
            'role' => UserRole::DISPATCHER,
            'name' => 'Operator Dispatcher',
            'phone' => '+998904445566',
        ]);

        // Driver User
        $this->driverUser = User::factory()->create([
            'role' => UserRole::DRIVER,
            'name' => 'Malik Al-Farouq',
            'phone' => '+998907770003',
        ]);

        $this->driverProfile = DriverProfile::create([
            'user_id' => $this->driverUser->id,
            'vehicle_type' => 'moto',
            'license_plate' => 'N/A',
            'status' => DriverStatus::AVAILABLE,
            'balance' => 214570.00,
            'rating' => 4.9,
            'current_lat' => 41.3111,
            'current_lng' => 69.2405,
        ]);
    }

    public function test_admin_has_all_permissions_automatically(): void
    {
        $this->assertTrue($this->admin->hasPermission('wallet.topup'));
        $this->assertTrue($this->admin->hasPermission('wallet.approve'));
        $this->assertTrue($this->admin->hasPermission('wallet.reject'));
        $this->assertTrue($this->admin->hasPermission('drivers.create'));
        $this->assertTrue($this->admin->hasPermission('drivers.edit'));
        $this->assertTrue($this->admin->hasPermission('orders.edit'));
        $this->assertTrue($this->admin->hasPermission('orders.cancel'));
        $this->assertTrue($this->admin->hasPermission('any.random.code'));
    }

    public function test_dispatcher_has_restricted_permissions_based_on_role(): void
    {
        // Allowed for Dispatcher (Orders & Live Radar)
        $this->assertTrue($this->dispatcher->hasPermission('orders.view'));
        $this->assertTrue($this->dispatcher->hasPermission('orders.create'));
        $this->assertTrue($this->dispatcher->hasPermission('orders.assign'));
        $this->assertTrue($this->dispatcher->hasPermission('drivers.view'));
        $this->assertTrue($this->dispatcher->hasPermission('map.view'));

        // Forbidden for Dispatcher (Admin only)
        $this->assertFalse($this->dispatcher->hasPermission('wallet.view'));
        $this->assertFalse($this->dispatcher->hasPermission('wallet.topup'));
        $this->assertFalse($this->dispatcher->hasPermission('wallet.approve'));
        $this->assertFalse($this->dispatcher->hasPermission('wallet.reject'));
        $this->assertFalse($this->dispatcher->hasPermission('drivers.create'));
        $this->assertFalse($this->dispatcher->hasPermission('drivers.edit'));
        $this->assertFalse($this->dispatcher->hasPermission('orders.edit'));
        $this->assertFalse($this->dispatcher->hasPermission('orders.cancel'));
    }

    public function test_dispatcher_cannot_topup_driver_wallet_only_admin_can(): void
    {
        // Dispatcher attempt -> 403 Forbidden
        $response = $this->actingAs($this->dispatcher)
            ->postJson("/api/dispatcher/drivers/{$this->driverProfile->id}/wallet/topup", [
                'amount' => 50000,
                'notes' => 'Dispatcher attempted topup',
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'PERMISSION_DENIED',
        ]);

        // Admin attempt -> 200 OK
        $adminRes = $this->actingAs($this->admin)
            ->postJson("/api/dispatcher/drivers/{$this->driverProfile->id}/wallet/topup", [
                'amount' => 50000,
                'notes' => 'Admin delivered balance',
            ]);

        $adminRes->assertStatus(200);
        $this->driverProfile->refresh();
        $this->assertEquals(264570.00, $this->driverProfile->balance);
    }

    public function test_driver_can_submit_topup_request_with_screenshot(): void
    {
        $file = UploadedFile::fake()->image('receipt.jpg');

        $response = $this->actingAs($this->driverUser)
            ->postJson('/api/driver/wallet/request-topup', [
                'amount' => 100000,
                'card_number' => '8600 1234 5678 9012',
                'notes' => 'Admin kartasiga tashlandi',
                'screenshot' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'request' => [
                'amount' => 100000.0,
                'status' => 'pending',
            ],
        ]);

        $this->assertDatabaseHas('wallet_topup_requests', [
            'driver_id' => $this->driverProfile->id,
            'amount' => 100000.00,
            'status' => 'pending',
            'card_number' => '8600 1234 5678 9012',
        ]);
    }

    public function test_admin_can_approve_topup_request_and_funds_delivered(): void
    {
        $topupReq = WalletTopupRequest::create([
            'driver_id' => $this->driverProfile->id,
            'user_id' => $this->driverUser->id,
            'amount' => 100000.00,
            'status' => 'pending',
            'card_number' => '8600 **** 4492',
        ]);

        $initialBalance = $this->driverProfile->balance; // 214570

        $response = $this->actingAs($this->admin)
            ->postJson("/api/dispatcher/wallet/topup-requests/{$topupReq->id}/approve");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'delivered_amount' => 100000.0,
            'new_balance' => $initialBalance + 100000.00,
        ]);

        $this->driverProfile->refresh();
        $this->assertEquals($initialBalance + 100000.00, $this->driverProfile->balance);

        $topupReq->refresh();
        $this->assertEquals('approved', $topupReq->status);
        $this->assertEquals($this->admin->id, $topupReq->processed_by_user_id);

        $this->assertDatabaseHas('driver_wallet_transactions', [
            'driver_id' => $this->driverProfile->id,
            'type' => 'deposit',
            'amount' => 100000.00,
            'balance_after' => $initialBalance + 100000.00,
        ]);
    }

    public function test_dispatcher_cannot_approve_topup_request(): void
    {
        $topupReq = WalletTopupRequest::create([
            'driver_id' => $this->driverProfile->id,
            'user_id' => $this->driverUser->id,
            'amount' => 50000.00,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->dispatcher)
            ->postJson("/api/dispatcher/wallet/topup-requests/{$topupReq->id}/approve");

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'PERMISSION_DENIED',
        ]);
    }

    public function test_admin_can_reject_topup_request(): void
    {
        $topupReq = WalletTopupRequest::create([
            'driver_id' => $this->driverProfile->id,
            'user_id' => $this->driverUser->id,
            'amount' => 50000.00,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/dispatcher/wallet/topup-requests/{$topupReq->id}/reject", [
                'reason' => 'Chekda ko\'rsatilgan summa tushmadi',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'status' => 'rejected',
        ]);

        $topupReq->refresh();
        $this->assertEquals('rejected', $topupReq->status);
        $this->assertEquals('Chekda ko\'rsatilgan summa tushmadi', $topupReq->rejection_reason);
    }

    public function test_dispatcher_cannot_reject_topup_request(): void
    {
        $topupReq = WalletTopupRequest::create([
            'driver_id' => $this->driverProfile->id,
            'user_id' => $this->driverUser->id,
            'amount' => 50000.00,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->dispatcher)
            ->postJson("/api/dispatcher/wallet/topup-requests/{$topupReq->id}/reject", [
                'reason' => 'Attempt by dispatcher',
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'PERMISSION_DENIED',
        ]);
    }

    public function test_admin_can_update_user_role(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson("/api/dispatcher/users/{$this->dispatcher->id}/role", [
                'role' => 'admin',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'user' => [
                'id' => $this->dispatcher->id,
                'role' => 'admin',
            ],
        ]);

        $this->dispatcher->refresh();
        $this->assertEquals(UserRole::ADMIN, $this->dispatcher->role);
        $this->assertTrue($this->dispatcher->hasPermission('wallet.topup'));
        $this->assertTrue($this->dispatcher->hasPermission('wallet.approve'));
    }

    public function test_dispatcher_cannot_update_user_role(): void
    {
        $response = $this->actingAs($this->dispatcher)
            ->postJson("/api/dispatcher/users/{$this->dispatcher->id}/role", [
                'role' => 'admin',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_delete_driver(): void
    {
        $driverId = $this->driverProfile->id;
        $driverUserId = $this->driverUser->id;

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/dispatcher/drivers/{$driverId}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertDatabaseMissing('driver_profiles', ['id' => $driverId]);
        $this->assertDatabaseMissing('users', ['id' => $driverUserId]);
    }

    public function test_dispatcher_cannot_delete_driver(): void
    {
        $driverId = $this->driverProfile->id;

        $response = $this->actingAs($this->dispatcher)
            ->deleteJson("/api/dispatcher/drivers/{$driverId}");

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'PERMISSION_DENIED',
        ]);

        $this->assertDatabaseHas('driver_profiles', ['id' => $driverId]);
    }

    public function test_admin_cannot_delete_busy_driver(): void
    {
        $this->driverProfile->update(['status' => DriverStatus::BUSY]);
        $driverId = $this->driverProfile->id;

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/dispatcher/drivers/{$driverId}");

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'CANNOT_DELETE_ACTIVE_DRIVER',
        ]);

        $this->assertDatabaseHas('driver_profiles', ['id' => $driverId]);
    }
}

