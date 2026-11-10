<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Portfolio;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\FeatureTestCase;

class RegistrationTransactionTest extends FeatureTestCase
{
    public function test_failed_portfolio_creation_does_not_leave_a_partial_account(): void
    {
        $this->withoutExceptionHandling();
        $event = 'eloquent.creating: '.Portfolio::class;
        Event::listen($event, fn () => throw new RuntimeException('Simulated portfolio failure'));

        try {
            $this->postJson('/api/auth/register', [
                'name' => 'New User',
                'email' => 'partial@example.com',
                'password' => 'a-secure-password',
                'password_confirmation' => 'a-secure-password',
            ]);
            $this->fail('Registration should fail when the default portfolio cannot be created.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated portfolio failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseMissing('users', ['email' => 'partial@example.com']);
    }
}
