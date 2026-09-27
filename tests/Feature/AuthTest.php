<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'dispatcher@zby.test',
            'password' => bcrypt('password'),
            'role' => UserRole::DISPATCHER,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'dispatcher@zby.test',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'token',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'role',
                ],
            ])
            ->assertJson([
                'user' => [
                    'email' => 'dispatcher@zby.test',
                    'role' => 'dispatcher',
                ],
            ]);
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'driver@zby.test',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'driver@zby.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_authenticated_user_can_fetch_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'driver@zby.test',
            'role' => UserRole::DRIVER,
        ]);

        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'user' => [
                    'email' => 'driver@zby.test',
                    'role' => 'driver',
                ],
            ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Logged out successfully']);

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_logout_handles_user_without_token_gracefully(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/logout');
        $response->assertStatus(200)
            ->assertJson(['message' => 'Logged out successfully']);
    }

    public function test_user_can_login_with_username(): void
    {
        User::factory()->create([
            'name' => 'Dispatcher Sarah',
            'username' => 'dispatcher',
            'email' => 'sarah@zby.test',
            'password' => bcrypt('secret123'),
            'role' => UserRole::DISPATCHER,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'login' => 'dispatcher',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.username', 'dispatcher')
            ->assertJsonPath('user.role', 'dispatcher');
    }

    public function test_driver_can_login_with_phone_number(): void
    {
        User::factory()->create([
            'name' => 'Alex Courier',
            'phone' => '+998907770001',
            'email' => 'alex@zby.test',
            'password' => bcrypt('driverpass'),
            'role' => UserRole::DRIVER,
        ]);

        // Test with full phone (+998...)
        $response1 = $this->postJson('/api/auth/login', [
            'phone' => '+998907770001',
            'password' => 'driverpass',
        ]);
        $response1->assertStatus(200)->assertJsonPath('user.phone', '+998907770001');

        // Test with digits only (998...)
        $response2 = $this->postJson('/api/auth/login', [
            'phone' => '998907770001',
            'password' => 'driverpass',
        ]);
        $response2->assertStatus(200)->assertJsonPath('user.phone', '+998907770001');
    }
}
