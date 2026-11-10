<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\FeatureTestCase;

class CredentialSecurityTest extends FeatureTestCase
{
    public function test_email_changes_require_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'current-password']);
        $original = $user->email;
        Password::createToken($user);
        Sanctum::actingAs($user);
        $this->patchJson('/api/auth/me', ['email' => 'takeover@example.com'])->assertUnprocessable();
        $this->patchJson('/api/auth/me', ['email' => 'takeover@example.com', 'current_password' => 'wrong'])->assertUnprocessable();
        $this->assertSame($original, $user->fresh()->email);
        $this->patchJson('/api/auth/me', ['email' => 'new@example.com', 'current_password' => 'current-password'])->assertOk();
        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $original]);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_unchanged_email_does_not_require_password_confirmation(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->patchJson('/api/auth/me', ['email' => $user->email, 'name' => 'Updated Name'])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_password_change_revokes_other_tokens_but_preserves_current_session(): void
    {
        $user = User::factory()->create(['password' => 'current-password']);
        $current = $user->createToken('current');
        $other = $user->createToken('other-device');
        Password::createToken($user);

        $this->withToken($current->plainTextToken)->patchJson('/api/auth/me', [
            'current_password' => 'current-password',
            'password' => 'a-new-secure-password',
            'password_confirmation' => 'a-new-secure-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-new-secure-password', $user->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_wrong_current_password_preserves_all_sessions(): void
    {
        $user = User::factory()->create(['password' => 'current-password']);
        $current = $user->createToken('current');
        $user->createToken('other-device');
        $this->withToken($current->plainTextToken)->patchJson('/api/auth/me', [
            'current_password' => 'wrong',
            'password' => 'a-new-secure-password',
            'password_confirmation' => 'a-new-secure-password',
        ])->assertUnprocessable();
        $this->assertSame(2, $user->tokens()->count());
        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));
    }
}
