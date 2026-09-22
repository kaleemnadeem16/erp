<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_fetch_their_own_account(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/account');

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.email', $user->email);
    }

    public function test_an_unauthenticated_request_to_view_the_account_gets_a_401(): void
    {
        $response = $this->getJson('/api/v1/account');

        $response->assertUnauthorized();
    }

    public function test_a_user_can_update_their_own_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)->patchJson('/api/v1/account', ['name' => 'New Name']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
        $this->assertSame('New Name', $user->refresh()->name);
    }

    public function test_updating_the_account_requires_a_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patchJson('/api/v1/account', ['name' => '']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_a_user_can_change_their_password_with_the_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($user)->patchJson('/api/v1/account/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertNoContent();

        $user->refresh();
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertFalse(Hash::check('old-password', $user->password));
    }

    public function test_changing_the_password_is_rejected_when_the_current_password_is_wrong(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($user)->patchJson('/api/v1/account/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
    }

    public function test_changing_the_password_requires_confirmation_to_match(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($user)->patchJson('/api/v1/account/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'does-not-match',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
    }

    public function test_an_unauthenticated_request_to_change_the_password_gets_a_401(): void
    {
        $response = $this->patchJson('/api/v1/account/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertUnauthorized();
    }
}
