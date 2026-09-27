<?php

namespace Tests\Feature;

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\DriverProfile;
use App\Models\DriverWalletTransaction;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverWalletAndCommissionTest extends TestCase
{
    use RefreshDatabase;

    private User $dispatcher;
    private User $driverUser;
    private DriverProfile $driverProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = User::factory()->create([
            'role' => UserRole::ADMIN,
            'name' => 'Test Admin Dispatcher',
        ]);

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
            'balance' => 215000.00,
            'rating' => 4.9,
            'current_lat' => 41.3111,
            'current_lng' => 69.2405,
        ]);
    }

    public function test_driver_accepting_assignment_deducts_10_percent_commission_fee(): void
    {
        // Create an order with total_amount = 25000
        $order = Order::factory()->create([
            'order_number' => 'Buyurtma 34',
            'total_amount' => 25000.00,
            'status' => OrderStatus::ASSIGNED,
            'current_driver_id' => $this->driverProfile->id,
            'created_by_user_id' => $this->dispatcher->id,
        ]);

        $assignment = OrderAssignment::create([
            'order_id' => $order->id,
            'driver_id' => $this->driverProfile->id,
            'dispatcher_id' => $this->dispatcher->id,
            'status' => \App\Enums\AssignmentStatus::PENDING,
            'assigned_at' => now(),
        ]);

        $initialBalance = $this->driverProfile->balance; // 215000

        // Driver accepts the assignment
        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/driver/assignments/{$assignment->id}/accept");

        $response->assertStatus(200);

        // Commission is 10% of 25000 = 2500
        $expectedCommission = 2500.00;
        $expectedBalance = $initialBalance - $expectedCommission; // 212500

        $this->driverProfile->refresh();
        $this->assertEquals($expectedBalance, $this->driverProfile->balance);

        $order->refresh();
        $this->assertEquals(10.0, $order->commission_rate);
        $this->assertEquals($expectedCommission, $order->commission_amount);

        // Verify transaction logged
        $this->assertDatabaseHas('driver_wallet_transactions', [
            'driver_id' => $this->driverProfile->id,
            'order_id' => $order->id,
            'type' => 'commission',
            'amount' => -2500.00,
            'balance_after' => $expectedBalance,
            'description' => 'Buyurtma #34 xizmat haqi (10%)',
        ]);
    }

    public function test_cancelling_assigned_order_refunds_commission_to_driver(): void
    {
        $order = Order::factory()->create([
            'order_number' => 'Buyurtma 38',
            'total_amount' => 4300.00,
            'status' => OrderStatus::ASSIGNED,
            'current_driver_id' => $this->driverProfile->id,
            'created_by_user_id' => $this->dispatcher->id,
        ]);

        $assignment = OrderAssignment::create([
            'order_id' => $order->id,
            'driver_id' => $this->driverProfile->id,
            'dispatcher_id' => $this->dispatcher->id,
            'status' => \App\Enums\AssignmentStatus::PENDING,
            'assigned_at' => now(),
        ]);

        // Accept and charge 430 so'm commission
        $this->actingAs($this->driverUser)
            ->postJson("/api/driver/assignments/{$assignment->id}/accept")
            ->assertStatus(200);

        $this->driverProfile->refresh();
        $balanceAfterCharge = $this->driverProfile->balance; // 215000 - 430 = 214570

        $this->assertEquals(214570.00, $balanceAfterCharge);

        // Dispatcher cancels order
        $cancelResponse = $this->actingAs($this->dispatcher)
            ->postJson("/api/dispatcher/orders/{$order->id}/cancel", [
                'reason' => 'Mijoz bekor qildi',
            ]);

        $cancelResponse->assertStatus(200);

        // Commission refunded back
        $this->driverProfile->refresh();
        $this->assertEquals(215000.00, $this->driverProfile->balance);

        $this->assertDatabaseHas('driver_wallet_transactions', [
            'driver_id' => $this->driverProfile->id,
            'order_id' => $order->id,
            'type' => 'refund',
            'amount' => 430.00,
            'balance_after' => 215000.00,
            'description' => 'Buyurtma #38 bekor qilindi (Komissiya qaytarildi)',
        ]);
    }

    public function test_driver_with_negative_balance_cannot_accept_new_assignment(): void
    {
        $this->driverProfile->update(['balance' => -500.00]);

        $order = Order::factory()->create([
            'order_number' => 'Buyurtma 50',
            'total_amount' => 10000.00,
            'status' => OrderStatus::ASSIGNED,
            'current_driver_id' => $this->driverProfile->id,
            'created_by_user_id' => $this->dispatcher->id,
        ]);

        $assignment = OrderAssignment::create([
            'order_id' => $order->id,
            'driver_id' => $this->driverProfile->id,
            'dispatcher_id' => $this->dispatcher->id,
            'status' => \App\Enums\AssignmentStatus::PENDING,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->driverUser)
            ->postJson("/api/driver/assignments/{$assignment->id}/accept");

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'error' => 'INSUFFICIENT_WALLET_BALANCE',
        ]);
    }

    public function test_driver_wallet_endpoint_returns_full_data_matching_ui(): void
    {
        // Create sample deposit
        DriverWalletTransaction::create([
            'driver_id' => $this->driverProfile->id,
            'order_id' => null,
            'type' => 'deposit',
            'amount' => 100000.00,
            'balance_after' => 315000.00,
            'description' => "Qo'lda to'ldirildi (Click)",
            'payment_method' => 'click',
        ]);

        $response = $this->actingAs($this->driverUser)
            ->getJson('/api/driver/wallet');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'driver' => [
                'id',
                'user_id',
                'name',
                'phone',
                'vehicle_type',
                'license_plate',
                'rating',
                'status',
            ],
            'wallet' => [
                'balance',
                'balance_formatted',
                'status_badge',
                'status_badge_type',
                'commission_rate_percent',
                'commission_rate_formatted',
                'info_message',
                'server_badge',
            ],
            'statistics' => [
                'total_revenue',
                'total_revenue_formatted',
                'total_commission',
                'total_commission_formatted',
                'net_earnings',
                'net_earnings_formatted',
            ],
            'weekly_chart',
            'transactions',
            'tariff_settings' => [
                'rate_per_km',
                'rate_per_km_formatted',
                'commission_rate',
                'commission_rate_formatted',
                'server_settings_label',
                'app_title',
            ],
        ]);

        $response->assertJson([
            'success' => true,
            'driver' => [
                'name' => 'Malik Al-Farouq',
                'phone' => '+998907770003',
                'vehicle_type' => 'moto',
                'rating' => 4.9,
            ],
            'wallet' => [
                'status_badge' => "Yetarli mablag'",
                'commission_rate_percent' => 10,
                'server_badge' => 'Backend / Server',
            ],
            'tariff_settings' => [
                'rate_per_km_formatted' => "1 km = 2 000 so'm",
                'commission_rate_formatted' => 'Komissiya 10%',
            ],
        ]);
    }

    public function test_driver_topup_endpoint_adds_balance_and_records_transaction(): void
    {
        $response = $this->actingAs($this->driverUser)
            ->postJson('/api/driver/wallet/topup', [
                'amount' => 50000,
                'payment_method' => 'click',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'balance' => 265000.00,
        ]);

        $this->driverProfile->refresh();
        $this->assertEquals(265000.00, $this->driverProfile->balance);

        $this->assertDatabaseHas('driver_wallet_transactions', [
            'driver_id' => $this->driverProfile->id,
            'type' => 'deposit',
            'amount' => 50000.00,
            'balance_after' => 265000.00,
            'payment_method' => 'click',
        ]);
    }

    public function test_dispatcher_can_manually_topup_driver_balance(): void
    {
        $response = $this->actingAs($this->dispatcher)
            ->postJson("/api/dispatcher/drivers/{$this->driverProfile->id}/wallet/topup", [
                'amount' => 100000,
                'notes' => 'Kassadan qabul qilindi',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'driver_id' => $this->driverProfile->id,
            'balance' => 315000.00,
        ]);

        $this->driverProfile->refresh();
        $this->assertEquals(315000.00, $this->driverProfile->balance);
    }
}
