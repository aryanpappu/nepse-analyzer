<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Notifications\EmailVerificationLink;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\FeatureTestCase;

class EmailVerificationTest extends FeatureTestCase
{
    public function test_resend_requires_an_account_and_sends_only_to_its_address(): void
    {
        Notification::fake();
        $this->postJson('/api/auth/email/verification-notification')->assertUnauthorized();
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/auth/email/verification-notification', ['email' => 'other@example.com'])->assertOk();
        Notification::assertSentTo($user, EmailVerificationLink::class);
    }

    public function test_signed_link_verifies_only_the_matching_account(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);
        $this->getJson($this->link($user))->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_another_account_cannot_use_a_valid_link(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs(User::factory()->unverified()->create());
        $this->getJson($this->link($user))->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_tampered_and_expired_links_are_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);
        $this->getJson($this->link($user).'&tampered=1')->assertForbidden();
        $this->getJson($this->link($user, true))->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_link_for_an_old_email_cannot_verify_a_changed_address(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->link($user);
        $user->update(['email' => 'changed@example.com', 'current_password' => 'password']);
        Sanctum::actingAs($user);
        $this->getJson($url)->assertForbidden();
    }

    public function test_verified_users_do_not_receive_duplicate_notifications(): void
    {
        Notification::fake();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/auth/email/verification-notification')->assertOk();
        Notification::assertNothingSent();
    }

    public function test_notification_uses_frontend_and_expiring_relative_signed_link(): void
    {
        config(['account.frontend_url' => 'https://app.example.com']);
        $user = User::factory()->unverified()->create();
        $message = (new EmailVerificationLink)->toMail($user);
        $this->assertStringStartsWith('https://app.example.com/verify-email?', $message->actionUrl);
        parse_str((string) parse_url($message->actionUrl, PHP_URL_QUERY), $params);
        $this->assertStringContainsString('/api/auth/email/verify/'.$user->id.'/', $params['verification_url']);
        $this->assertStringContainsString('expires=', $params['verification_url']);
        $this->assertStringContainsString('signature=', $params['verification_url']);
    }

    public function test_changing_email_clears_verification_and_preserves_portfolio_access(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->patchJson('/api/auth/me', ['email' => 'new-address@example.com', 'current_password' => 'password'])
            ->assertOk()->assertJsonPath('data.email_verified_at', null);
        $this->getJson('/api/portfolios')->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    private function link(User $user, bool $expired = false): string
    {
        return URL::temporarySignedRoute('verification.verify', $expired ? now()->subMinute() : now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ], absolute: false);
    }
}
