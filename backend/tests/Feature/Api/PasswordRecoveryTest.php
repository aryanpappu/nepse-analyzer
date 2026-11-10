<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Notifications\PasswordResetLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\FeatureTestCase;

class PasswordRecoveryTest extends FeatureTestCase
{
    public function test_reset_requests_do_not_reveal_whether_an_email_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $known = $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'unknown@example.com'])->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentTo($user, PasswordResetLink::class);
    }

    public function test_link_uses_configured_frontend_and_encodes_email(): void
    {
        config(['account.frontend_url' => 'https://example.com']);
        $user = User::factory()->create(['email' => 'user+test@example.com']);
        $mail = (new PasswordResetLink('secret'))->toMail($user);
        $this->assertSame('https://example.com/reset-password?token=secret&email=user%2Btest%40example.com', $mail->actionUrl);
    }

    public function test_reset_changes_password_revokes_sessions_and_consumes_token(): void
    {
        $user = User::factory()->create();
        $user->createToken('old-session');
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'];
        $this->postJson('/api/auth/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable();
    }

    public function test_invalid_expired_or_unconfirmed_resets_preserve_password_and_tokens(): void
    {
        $user = User::factory()->create();
        $hash = $user->password;
        $user->createToken('active');
        $payload = ['email' => $user->email, 'token' => 'invalid', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'];
        $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable();
        $payload['token'] = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable();
        $this->travelBack();
        $payload['password_confirmation'] = 'different-password';
        $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable();
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(1, $user->tokens()->count());
    }
}
