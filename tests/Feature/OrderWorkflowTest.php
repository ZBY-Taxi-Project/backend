<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $dispatcher;
    protected string $dispatcherToken;

    protected User $driverUser1;
    protected DriverProfile $driverProfile1;
    protected string $driverToken1;

    protected User $driverUser2;
    protected DriverProfile $driverProfile2;
    protected string $driverToken2;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Dispatcher
        $this->dispatcher = User::factory()->create([
            'email' => 'dispatcher@zby.test',
            'role' => UserRole::DISPATCHER,
        ]);
        $this->dispatcherToken = $this->dispatcher->createToken('test_dispatcher')->plainTextToken;

        // 2. Setup Driver 1 (Available)
        $this->driverUser1 = User::factory()->create([
            'email' => 'driver1@zby.test',
            'role' => UserRole::DRIVER,
        ]);
        $this->driverProfile1 = DriverProfile::create([
            'user_id' => $this->driverUser1->id,
            'vehicle_type' => 'Toyota Prius',
            'license_plate' => '01 A 777 AA',
            'status' => DriverStatus::AVAILABLE,
        ]);
        $this->driverToken1 = $this->driverUser1->createToken('test_driver_1')->plainTextToken;

        // 3. Setup Driver 2 (Available)
        $this->driverUser2 = User::factory()->create([
            'email' => 'driver2@zby.test',
            'role' => UserRole::DRIVER,
        ]);
        $this->driverProfile2 = DriverProfile::create([
            'user_id' => $this->driverUser2->id,
            'vehicle_type' => 'Chevrolet Cobalt',
            'license_plate' => '01 B 123 BB',
            'status' => DriverStatus::AVAILABLE,
        ]);
        $this->driverToken2 = $this->driverUser2->createToken('test_driver_2')->plainTextToken;
    }

    public function test_dispatcher_can_create_new_order(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->dispatcherToken)
            ->postJson('/api/dispatcher/orders', [
                'customer_name' => 'John Doe',
                'customer_phone' => '+998901234567',
                'pickup_address' => 'Amir Timur 1',
                'delivery_address' => 'Navoi 10',
                'total_amount' => 25.50,
                'notes' => 'Ring doorbell twice',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Order created successfully',
                'order' => [
                    'customer_name' => 'John Doe',
                    'status' => 'pending_dispatch',
                ],
            ]);

        $this->assertDatabaseHas('orders', [
            'customer_name' => 'John Doe',
            'status' => 'pending_dispatch',
        ]);
    }

    public function test_dispatcher_can_assign_order_to_available_driver(): void
    {
        $order = Order::factory()->create([
            'order_number' => 'ORD-TEST-1',
            'customer_name' => 'Alice',
            'customer_phone' => '+1234567890',
            'pickup_address' => 'A',
            'delivery_address' => 'B',
            'status' => OrderStatus::PENDING_DISPATCH,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->dispatcherToken)
            ->postJson("/api/dispatcher/orders/{$order->id}/assign", [
                'driver_id' => $this->driverProfile1->id,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Order assigned successfully',
                'order' => [
                    'status' => 'assigned',
                    'current_driver_id' => $this->driverProfile1->id,
                ],
            ]);

        $this->assertDatabaseHas('order_assignments', [
            'order_id' => $order->id,
            'driver_id' => $this->driverProfile1->id,
            'status' => AssignmentStatus::PENDING->value,
        ]);
    }

    public function test_dispatcher_cannot_assign_unavailable_driver(): void
    {
        // Mark driver as BUSY
        $this->driverProfile1->update(['status' => DriverStatus::BUSY]);

        $order = Order::factory()->create([
            'order_number' => 'ORD-TEST-2',
            'customer_name' => 'Bob',
            'customer_phone' => '+1234567890',
            'pickup_address' => 'A',
            'delivery_address' => 'B',
            'status' => OrderStatus::PENDING_DISPATCH,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->dispatcherToken)
            ->postJson("/api/dispatcher/orders/{$order->id}/assign", [
                'driver_id' => $this->driverProfile1->id,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'DRIVER_UNAVAILABLE',
            ]);
    }

    public function test_driver_can_accept_assignment_and_advance_delivery_lifecycle(): void
    {
        // 1. Create order and assign to driver 1
        $order = Order::factory()->create([
            'order_number' => 'ORD-LIFECYCLE',
            'customer_name' => 'Charlie',
            'customer_phone' => '+1234567890',
            'pickup_address' => 'Store 1',
            'delivery_address' => 'Client 1',
            'status' => OrderStatus::PENDING_DISPATCH,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->dispatcherToken)
            ->postJson("/api/dispatcher/orders/{$order->id}/assign", [
                'driver_id' => $this->driverProfile1->id,
            ]);

        // 2. Driver fetches pending assignment
        $pendingRes = $this->withHeader('Authorization', 'Bearer '.$this->driverToken1)
            ->getJson('/api/driver/assignment/pending');

        $pendingRes->assertStatus(200);
        $assignmentId = $pendingRes->json('pending_assignment.id');
        $this->assertNotNull($assignmentId);

        // 3. Driver accepts assignment
        $acceptRes = $this->withHeader('Authorization', 'Bearer '.$this->driverToken1)
            ->postJson("/api/driver/assignments/{$assignmentId}/accept");

        $acceptRes->assertStatus(200)
            ->assertJson([
                'order' => ['status' => 'driver_accepted'],
            ]);

        $this->assertEquals(DriverStatus::BUSY, $this->driverProfile1->fresh()->status);

        // 4. Driver advances to PICKED_UP
        $pickupRes = $this->withHeader('Authorization', 'Bearer '.$this->driverToken1)
            ->postJson("/api/driver/orders/{$order->id}/status", [
                'status' => 'picked_up',
            ]);
        $pickupRes->assertStatus(200)
            ->assertJson(['order' => ['status' => 'picked_up']]);

        // 5. Driver advances to IN_TRANSIT
        $transitRes = $this->withHeader('Authorization', 'Bearer '.$this->driverToken1)
            ->postJson("/api/driver/orders/{$order->id}/status", [
                'status' => 'in_transit',
            ]);
        $transitRes->assertStatus(200)
            ->assertJson(['order' => ['status' => 'in_transit']]);

        // 6. Driver advances to DELIVERED
        $deliveredRes = $this->withHeader('Authorization', 'Bearer '.$this->driverToken1)
            ->postJson("/api/driver/orders/{$order->id}/status", [
                'status' => 'delivered',
            ]);
        $deliveredRes->assertStatus(200)
            ->assertJson(['order' => ['status' => 'delivered']]);

        // Driver should automatically become AVAILABLE again once delivery finishes
        $this->assertEquals(DriverStatus::AVAILABLE, $this->driverProfile1->fresh()->status);
    }

    public function test_illegal_order_state_transition_is_rejected(): void
    {
        $order = Order::factory()->create([
            'order_number' => 'ORD-ILLEGAL',
            'customer_name' => 'Dave',
            'customer_phone' => '+1234567890',
            'pickup_address' => 'Store 1',
            'delivery_address' => 'Client 1',
            'status' => OrderStatus::DELIVERED,
            'current_driver_id' => $this->driverProfile1->id,
        ]);

        // Attempting to advance a delivered order to 'assigned' must fail with 422
        $response = $this->withHeader('Authorization', 'Bearer '.$this->driverToken1)
            ->postJson("/api/driver/orders/{$order->id}/status", [
                'status' => 'assigned',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'INVALID_STATE_TRANSITION',
            ]);
    }

    public function test_driver_rejection_returns_order_to_pending_dispatch(): void
    {
        $order = Order::factory()->create([
            'order_number' => 'ORD-REJECT',
            'customer_name' => 'Eve',
            'customer_phone' => '+1234567890',
            'pickup_address' => 'Store 1',
            'delivery_address' => 'Client 1',
            'status' => OrderStatus::PENDING_DISPATCH,
        ]);

        // Dispatcher assigns
        $this->withHeader('Authorization', 'Bearer '.$this->dispatcherToken)
            ->postJson("/api/dispatcher/orders/{$order->id}/assign", [
                'driver_id' => $this->driverProfile1->id,
            ]);

        $assignment = $order->fresh()->latestAssignment;

        // Driver rejects
        $rejectRes = $this->withHeader('Authorization', 'Bearer '.$this->driverToken1)
            ->postJson("/api/driver/assignments/{$assignment->id}/reject", [
                'reason' => 'Flat tire on highway',
            ]);

        $rejectRes->assertStatus(200);

        // Order is returned to pending dispatch and driver is detached
        $freshOrder = $order->fresh();
        $this->assertEquals(OrderStatus::PENDING_DISPATCH, $freshOrder->status);
        $this->assertNull($freshOrder->current_driver_id);
    }

    public function test_driver_cannot_access_dispatcher_routes(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->driverToken1)
            ->getJson('/api/dispatcher/orders');

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'FORBIDDEN',
            ]);
    }
}
