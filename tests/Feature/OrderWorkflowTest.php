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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $dispatcher;
    protected User $driverUser1;
    protected DriverProfile $driverProfile1;
    protected User $driverUser2;
    protected DriverProfile $driverProfile2;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Dispatcher
        $this->dispatcher = User::factory()->create([
            'email' => 'dispatcher@zby.test',
            'role' => UserRole::DISPATCHER,
        ]);

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
    }

    public function test_dispatcher_can_create_new_order(): void
    {
        Sanctum::actingAs($this->dispatcher);

        $response = $this->postJson('/api/dispatcher/orders', [
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
        Sanctum::actingAs($this->dispatcher);

        $order = Order::factory()->create([
            'order_number' => 'ORD-TEST-1',
            'customer_name' => 'Alice',
            'customer_phone' => '+1234567890',
            'pickup_address' => 'A',
            'delivery_address' => 'B',
            'status' => OrderStatus::PENDING_DISPATCH,
        ]);

        $response = $this->postJson("/api/dispatcher/orders/{$order->id}/assign", [
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
        Sanctum::actingAs($this->dispatcher);

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

        $response = $this->postJson("/api/dispatcher/orders/{$order->id}/assign", [
            'driver_id' => $this->driverProfile1->id,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'DRIVER_UNAVAILABLE',
            ]);
    }

    public function test_driver_can_accept_assignment_and_advance_delivery_lifecycle(): void
    {
        // 1. Dispatcher creates and assigns order to driver 1
        Sanctum::actingAs($this->dispatcher);

        $order = Order::factory()->create([
            'order_number' => 'ORD-LIFECYCLE',
            'customer_name' => 'Charlie',
            'customer_phone' => '+1234567890',
            'pickup_address' => 'Store 1',
            'delivery_address' => 'Client 1',
            'status' => OrderStatus::PENDING_DISPATCH,
        ]);

        $this->postJson("/api/dispatcher/orders/{$order->id}/assign", [
            'driver_id' => $this->driverProfile1->id,
        ]);

        // 2. Driver fetches pending assignment
        Sanctum::actingAs($this->driverUser1);

        $pendingRes = $this->getJson('/api/driver/assignment/pending');
        $pendingRes->assertStatus(200);
        $assignmentId = $pendingRes->json('pending_assignment.id');
        $this->assertNotNull($assignmentId);

        // 3. Driver accepts assignment
        $acceptRes = $this->postJson("/api/driver/assignments/{$assignmentId}/accept");

        $acceptRes->assertStatus(200)
            ->assertJson([
                'order' => ['status' => 'driver_accepted'],
            ]);

        $this->assertEquals(DriverStatus::BUSY, $this->driverProfile1->fresh()->status);

        // 4. Driver advances to PICKED_UP
        $pickupRes = $this->postJson("/api/driver/orders/{$order->id}/status", [
            'status' => 'picked_up',
        ]);
        $pickupRes->assertStatus(200)
            ->assertJson(['order' => ['status' => 'picked_up']]);

        // 5. Driver advances to IN_TRANSIT
        $transitRes = $this->postJson("/api/driver/orders/{$order->id}/status", [
            'status' => 'in_transit',
        ]);
        $transitRes->assertStatus(200)
            ->assertJson(['order' => ['status' => 'in_transit']]);

        // 6. Driver advances to DELIVERED
        $deliveredRes = $this->postJson("/api/driver/orders/{$order->id}/status", [
            'status' => 'delivered',
        ]);
        $deliveredRes->assertStatus(200)
            ->assertJson(['order' => ['status' => 'delivered']]);

        // Driver should automatically become AVAILABLE again once delivery finishes
        $this->assertEquals(DriverStatus::AVAILABLE, $this->driverProfile1->fresh()->status);
    }

    public function test_illegal_order_state_transition_is_rejected(): void
    {
        Sanctum::actingAs($this->driverUser1);

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
        $response = $this->postJson("/api/driver/orders/{$order->id}/status", [
            'status' => 'assigned',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'INVALID_STATE_TRANSITION',
            ]);
    }

    public function test_driver_rejection_returns_order_to_pending_dispatch(): void
    {
        // 1. Dispatcher assigns
        Sanctum::actingAs($this->dispatcher);

        $order = Order::factory()->create([
            'order_number' => 'ORD-REJECT',
            'customer_name' => 'Eve',
            'customer_phone' => '+1234567890',
            'pickup_address' => 'Store 1',
            'delivery_address' => 'Client 1',
            'status' => OrderStatus::PENDING_DISPATCH,
        ]);

        $this->postJson("/api/dispatcher/orders/{$order->id}/assign", [
            'driver_id' => $this->driverProfile1->id,
        ]);

        $assignment = $order->fresh()->latestAssignment;

        // 2. Driver rejects
        Sanctum::actingAs($this->driverUser1);

        $rejectRes = $this->postJson("/api/driver/assignments/{$assignment->id}/reject", [
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
        Sanctum::actingAs($this->driverUser1);

        $response = $this->getJson('/api/dispatcher/orders');

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'FORBIDDEN',
            ]);
    }

    public function test_dispatcher_cancelling_order_cancels_active_assignments_and_releases_driver(): void
    {
        Sanctum::actingAs($this->dispatcher);

        $order = Order::factory()->create([
            'order_number' => 'ORD-CANCEL-TEST',
            'customer_name' => 'Cancel Test',
            'customer_phone' => '+1234567890',
            'pickup_address' => 'Store 1',
            'delivery_address' => 'Client 1',
            'status' => OrderStatus::PENDING_DISPATCH,
        ]);

        $this->postJson("/api/dispatcher/orders/{$order->id}/assign", [
            'driver_id' => $this->driverProfile1->id,
        ]);

        // Driver accepts order
        Sanctum::actingAs($this->driverUser1);
        $assignment = $order->fresh()->latestAssignment;
        $this->postJson("/api/driver/assignments/{$assignment->id}/accept")->assertStatus(200);

        $this->assertEquals(DriverStatus::BUSY, $this->driverProfile1->fresh()->status);
        $this->assertEquals(OrderStatus::DRIVER_ACCEPTED, $order->fresh()->status);

        // Dispatcher cancels order
        Sanctum::actingAs($this->dispatcher);
        $cancelRes = $this->postJson("/api/dispatcher/orders/{$order->id}/cancel", [
            'reason' => 'Customer called to cancel',
        ]);
        $cancelRes->assertStatus(200);

        $this->assertEquals(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertEquals(AssignmentStatus::CANCELLED, $assignment->fresh()->status);
        // Driver must be released back to AVAILABLE
        $this->assertEquals(DriverStatus::AVAILABLE, $this->driverProfile1->fresh()->status);
    }

    public function test_driver_cannot_accept_cancelled_order(): void
    {
        Sanctum::actingAs($this->dispatcher);

        $order = Order::factory()->create([
            'order_number' => 'ORD-ZOMBIE-TEST',
            'customer_name' => 'Zombie Test',
            'customer_phone' => '+1234567890',
            'pickup_address' => 'Store 1',
            'delivery_address' => 'Client 1',
            'status' => OrderStatus::PENDING_DISPATCH,
        ]);

        $this->postJson("/api/dispatcher/orders/{$order->id}/assign", [
            'driver_id' => $this->driverProfile1->id,
        ]);

        $assignment = $order->fresh()->latestAssignment;

        // Dispatcher cancels order before driver accepts
        $this->postJson("/api/dispatcher/orders/{$order->id}/cancel", [
            'reason' => 'Customer cancelled early',
        ])->assertStatus(200);

        // Driver now tries to accept the offer
        Sanctum::actingAs($this->driverUser1);
        $acceptRes = $this->postJson("/api/driver/assignments/{$assignment->id}/accept");

        // Must fail with 409 conflict and not resurrect the order!
        $acceptRes->assertStatus(409);
        $this->assertEquals(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_busy_driver_cannot_accept_another_order(): void
    {
        Sanctum::actingAs($this->dispatcher);

        // Order 1
        $order1 = Order::factory()->create([
            'order_number' => 'ORD-BUSY-1',
            'customer_name' => 'Customer 1',
            'customer_phone' => '+1234567890',
            'pickup_address' => 'A',
            'delivery_address' => 'B',
            'status' => OrderStatus::PENDING_DISPATCH,
        ]);
        $this->postJson("/api/dispatcher/orders/{$order1->id}/assign", [
            'driver_id' => $this->driverProfile1->id,
        ]);
        $assignment1 = $order1->fresh()->latestAssignment;

        // Driver accepts order 1 -> becomes BUSY
        Sanctum::actingAs($this->driverUser1);
        $this->postJson("/api/driver/assignments/{$assignment1->id}/accept")->assertStatus(200);
        $this->assertEquals(DriverStatus::BUSY, $this->driverProfile1->fresh()->status);

        // Order 2 created & assignment assigned to Driver 1 directly in DB (simulating concurrent race)
        $order2 = Order::factory()->create([
            'order_number' => 'ORD-BUSY-2',
            'customer_name' => 'Customer 2',
            'customer_phone' => '+1234567890',
            'pickup_address' => 'C',
            'delivery_address' => 'D',
            'status' => OrderStatus::ASSIGNED,
            'current_driver_id' => $this->driverProfile1->id,
        ]);
        $assignment2 = \App\Models\OrderAssignment::create([
            'order_id' => $order2->id,
            'driver_id' => $this->driverProfile1->id,
            'assigned_by' => $this->dispatcher->id,
            'status' => AssignmentStatus::PENDING,
            'offered_at' => now(),
        ]);

        // Driver attempts to accept order 2 while already BUSY on order 1
        $accept2Res = $this->postJson("/api/driver/assignments/{$assignment2->id}/accept");
        $accept2Res->assertStatus(409);
        $this->assertStringContainsString('busy', strtolower($accept2Res->json('message') ?? ''));
    }
}
