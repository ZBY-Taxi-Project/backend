<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'ORD-'.strtoupper(Str::random(6)),
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->phoneNumber(),
            'pickup_address' => fake()->streetAddress().', Tashkent',
            'pickup_lat' => fake()->latitude(41.25, 41.35),
            'pickup_lng' => fake()->longitude(69.20, 69.35),
            'delivery_address' => fake()->streetAddress().', Tashkent',
            'delivery_lat' => fake()->latitude(41.25, 41.35),
            'delivery_lng' => fake()->longitude(69.20, 69.35),
            'total_amount' => fake()->randomFloat(2, 10, 100),
            'status' => OrderStatus::PENDING_DISPATCH,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
