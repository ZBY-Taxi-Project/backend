<?php

namespace Tests\Feature;

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Exceptions\OrderAssignmentException;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\OrderStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConcurrencyAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_concurrent_assignment_conflict_defense(): void
    {
        $dispatcher = User::factory()->create(['role' => UserRole::DISPATCHER]);
        $driverUser = User::factory()->create(['role' => UserRole::DRIVER]);
        $driverProfile = DriverProfile::create([
            'user_id' => $driverUser->id,
            'vehicle_type' => 'Toyota Prius',
            'license_plate' => '01 A 777 AA',
            'status' => DriverStatus::AVAILABLE,
        ]);

        $order = Order::factory()->create([
            'order_number' => 'ORD-RACE-1',
            'status' => OrderStatus::PENDING_DISPATCH,
        ]);

        $service = new AssignmentService(new OrderStateMachine());

        // First assignment succeeds
        $assignment1 = $service->assign($order, $driverProfile, $dispatcher);
        $this->assertNotNull($assignment1->id);
        $this->assertEquals(OrderStatus::ASSIGNED, $order->fresh()->status);

        // Second assignment immediately attempted on the same order must fail
        $this->expectException(OrderAssignmentException::class);
        $this->expectExceptionMessage("Order is currently 'assigned' and cannot be assigned.");

        $service->assign($order->fresh(), $driverProfile, $dispatcher);
    }

    public function test_driver_unavailable_blocks_assignment_service(): void
    {
        $dispatcher = User::factory()->create(['role' => UserRole::DISPATCHER]);
        $driverUser = User::factory()->create(['role' => UserRole::DRIVER]);
        $driverProfile = DriverProfile::create([
            'user_id' => $driverUser->id,
            'vehicle_type' => 'Chevrolet Cobalt',
            'license_plate' => '01 B 123 BB',
            'status' => DriverStatus::OFFLINE,
        ]);

        $order = Order::factory()->create([
            'order_number' => 'ORD-RACE-2',
            'status' => OrderStatus::PENDING_DISPATCH,
        ]);

        $service = new AssignmentService(new OrderStateMachine());

        $this->expectException(OrderAssignmentException::class);
        $service->assign($order, $driverProfile, $dispatcher);
    }
}
