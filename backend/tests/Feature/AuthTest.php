<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_get_profile(): void
    {
        $user = User::factory()->create(['username' => 'jemaat']);

        $token = $this->postJson('/api/login', ['username' => 'jemaat', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.username', 'jemaat')
            ->json('token');

        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['username' => 'jemaat']);

        $this->postJson('/api/login', ['username' => 'jemaat', 'password' => 'salah'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create(['username' => 'jemaat', 'is_active' => false]);

        $this->postJson('/api/login', ['username' => 'jemaat', 'password' => 'password'])
            ->assertUnprocessable();
    }

    public function test_login_requires_username_not_email(): void
    {
        User::factory()->create(['username' => 'jemaat', 'email' => 'a@gkt.test']);

        $this->postJson('/api/login', ['email' => 'a@gkt.test', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_admin_can_create_user_with_username_without_email(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Bendahara Baru',
            'username' => 'bendahara2',
            'password' => 'rahasia123',
            'role' => 'bendahara',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.username', 'bendahara2')->assertJsonPath('data.email', null);

        $this->postJson('/api/login', ['username' => 'bendahara2', 'password' => 'rahasia123'])->assertOk();
    }

    public function test_guest_cannot_access_api(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }
}
