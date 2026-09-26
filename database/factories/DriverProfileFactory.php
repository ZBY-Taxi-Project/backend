<?php

namespace Database\Factories;

use App\Enums\DriverStatus;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DriverProfile>
 */
class DriverProfileFactory extends Factory
{
    protected $model = DriverProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'vehicle_type' => fake()->randomElement(['Toyota Prius', 'Chevrolet Cobalt', 'Honda Delivery Moto']),
            'license_plate' => fake()->bothify('01 ? ### ??'),
            'status' => DriverStatus::AVAILABLE,
            'current_lat' => fake()->latitude(41.25, 41.35),
            'current_lng' => fake()->longitude(69.20, 69.35),
            'last_active_at' => now(),
        ];
    }
}
