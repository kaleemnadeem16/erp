<?php

namespace Tests\Feature\Auth;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class AuthInformationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_responses_do_not_expose_credentials_or_accept_another_user_target(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.member.view']);
        $other = User::factory()->create();
        $token = $actor->createToken('device')->plainTextToken;
        foreach (['user', 'account'] as $path) {
            $response = $this->actingAs($actor)->getJson("/api/v1/{$path}?user_id={$other->id}")->assertOk()->assertJsonPath('data.id', $actor->id)
                ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token')->assertJsonMissingPath('data.tokens')->assertJsonMissingPath('data.credential_version');
            $this->assertStringNotContainsString($token, $response->getContent());
            $this->assertStringNotContainsString($actor->password, $response->getContent());
        }
        $this->patchJson('/api/v1/account', ['id' => $other->id, 'name' => 'My new name', 'email' => 'replacement@example.com', 'permissions' => ['administration.access.assign']])->assertOk()->assertJsonPath('data.id', $actor->id);
        $this->assertSame('My new name', $actor->fresh()->name);
        $this->assertSame($other->name, $other->fresh()->name);
        $this->assertSame($actor->email, $actor->fresh()->email);
        $this->assertFalse($actor->fresh()->can('administration.access.assign'));
        $request = Request::create('/api/v1/user');
        $request->setUserResolver(fn () => $other);
        $nested = (new UserResource($actor))->resolve($request);
        $this->assertArrayNotHasKey('permissions', $nested);
    }

    public function test_foreign_company_credentials_and_bearer_tokens_cannot_authenticate_in_current_company(): void
    {
        DB::table('companies')->insert(['id' => 2, 'name' => 'Foreign company']);
        $original = app(CompanyContext::class);
        app()->instance(CompanyContext::class, new class extends CompanyContext
        {
            public function id(): int
            {
                return 2;
            }
        });
        $foreign = User::factory()->create(['email' => 'foreign@example.com', 'password' => 'Password123!']);
        $token = $foreign->createToken('foreign-device')->plainTextToken;
        app()->instance(CompanyContext::class, $original);
        $this->postJson('/api/v1/login', ['email' => $foreign->email, 'password' => 'Password123!', 'device_name' => 'try'])->assertUnprocessable()->assertJsonMissingPath('meta.token')->assertJsonMissing(['email' => $foreign->email]);
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/user')->assertUnauthorized()->assertJsonMissing(['email' => $foreign->email]);
    }
}
