<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_available_and_guest_is_redirected_from_dashboard(): void
    {
        $this->get('/login')->assertOk()->assertSee('SIKORDIK');
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_active_user_can_login_and_login_is_audited(): void
    {
        $user = $this->createUserWithRole(attributes: ['email' => 'admin@example.test', 'password' => Hash::make('rahasia-yang-aman')]);

        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'rahasia-yang-aman'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create(['email' => 'nonaktif@example.test', 'password' => Hash::make('rahasia-yang-aman'), 'is_active' => false]);

        $this->post('/login', ['email' => 'nonaktif@example.test', 'password' => 'rahasia-yang-aman'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
