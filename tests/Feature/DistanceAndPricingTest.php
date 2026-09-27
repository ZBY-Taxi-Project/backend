<?php

namespace Tests\Feature;

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DistanceAndPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pricing_estimate_calculates_distance_and_price_at_2000_som_per_km(): void
    {
        $response = $this->postJson('/api/pricing/estimate', [
            'pickup_lat' => 41.3111,
            'pickup_lng' => 69.2405,
            'delivery_lat' => 41.3350,
            'delivery_lng' => 69.2800,
            'rate_per_km' => 2000,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'distance_km',
            'rate_per_km',
            'base_fare',
            'estimated_price',
            'formatted_price',
            'currency',
        ]);

        $data = $response->json();
        $this->assertEquals(2000, $data['rate_per_km']);
        $this->assertGreaterThan(3.5, $data['distance_km']);
        $this->assertLessThan(5.0, $data['distance_km']);
        // Price should be distance * 2000 rounded to 100 so'm
        $this->assertEquals(round($data['distance_km'] * 2000, -2), $data['estimated_price']);
    }

    public function test_order_creation_automatically_computes_distance_and_price(): void
    {
        $dispatcher = User::factory()->create([
            'role' => UserRole::DISPATCHER,
        ]);

        Sanctum::actingAs($dispatcher);

        $response = $this->postJson('/api/dispatcher/orders', [
            'customer_name' => 'John Doe',
            'customer_phone' => '+998901234567',
            'pickup_address' => 'Navoi 1',
            'pickup_lat' => 41.3111,
            'pickup_lng' => 69.2405,
            'delivery_address' => 'Chilanzar 5',
            'delivery_lat' => 41.3350,
            'delivery_lng' => 69.2800,
            'rate_per_km' => 2000,
        ]);

        $response->assertStatus(201);
        $order = Order::first();

        $this->assertNotNull($order);
        $this->assertGreaterThan(3.5, $order->estimated_distance_km);
        $this->assertEquals(2000, $order->rate_per_km);
        $this->assertGreaterThan(0, $order->total_amount);
        $this->assertEquals(round($order->estimated_distance_km * 2000, -2), $order->total_amount);
    }

    public function test_driver_location_update_accumulates_movement_and_updates_live_price(): void
    {
        $driverUser = User::factory()->create([
            'role' => UserRole::DRIVER,
        ]);

        $driver = DriverProfile::create([
            'user_id' => $driverUser->id,
            'vehicle_type' => 'Cobalt',
            'license_plate' => '01A111AA',
            'status' => DriverStatus::AVAILABLE,
            'current_lat' => 41.3100,
            'current_lng' => 69.2400,
        ]);

        $order = Order::create([
            'order_number' => 'ORD-LIVE-PRICE',
            'customer_name' => 'Aziz',
            'customer_phone' => '+998901112233',
            'pickup_address' => 'Point A',
            'pickup_lat' => 41.3100,
            'pickup_lng' => 69.2400,
            'delivery_address' => 'Point B',
            'delivery_lat' => 41.3500,
            'delivery_lng' => 69.2800,
            'estimated_distance_km' => 5.0,
            'actual_distance_km' => 0.0,
            'rate_per_km' => 2000,
            'base_fare' => 0,
            'total_amount' => 10000,
            'status' => OrderStatus::IN_TRANSIT,
            'current_driver_id' => $driver->id,
        ]);

        Sanctum::actingAs($driverUser);

        // Driver moves ~1.1 km to (41.3200, 69.2400)
        $response = $this->postJson('/api/driver/profile/location', [
            'lat' => 41.3200,
            'lng' => 69.2400,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertGreaterThan(1.0, $data['distance_moved_km']);
        $this->assertNotNull($data['active_order']);
        $this->assertEquals($order->id, $data['active_order']['id']);
        $this->assertGreaterThan(1.0, $data['active_order']['actual_distance_km']);
        $this->assertGreaterThan(2000, $data['active_order']['current_price_som']);

        $freshOrder = $order->fresh();
        $this->assertGreaterThan(1.0, $freshOrder->actual_distance_km);
        $this->assertEquals($data['active_order']['current_price_som'], $freshOrder->total_amount);
    }
}
