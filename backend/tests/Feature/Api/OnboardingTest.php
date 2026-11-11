<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\Stock;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * HTTP coverage for `/api/onboarding`.
 *
 * What is being protected here is that the checklist is *derived*. A stored
 * "has onboarded" flag would be set by the UI and would then be wrong for
 * every user whose data arrived by another route — the CLI importers, or a
 * restored backup — telling them to redo work already done. So the tests
 * assert that recording the data, by any means, is what marks a step complete.
 */
final class OnboardingTest extends FeatureTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['benchmark_fd_rate' => null]);

        Sanctum::actingAs($this->user);
    }

    #[Test]
    public function it_requires_a_signed_in_user(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/onboarding')->assertUnauthorized();
    }

    #[Test]
    public function it_reports_every_step_outstanding_on_a_bare_install(): void
    {
        $response = $this->getJson('/api/onboarding')->assertOk();

        $response->assertJsonPath('data.complete', false);
        $response->assertJsonPath('data.completed', 0);
        $response->assertJsonPath('data.total', 5);

        // The card asks one question, not six, so the first outstanding step
        // has to be the one the user can actually act on now.
        $response->assertJsonPath('data.next', 'portfolio');
        $this->assertStringNotContainsString('php artisan', $response->getContent());
    }

    #[Test]
    public function administrators_can_see_shared_market_setup_instructions(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson('/api/onboarding')->assertOk();

        $response->assertJsonPath('data.total', 6);
        $response->assertJsonPath('data.next', 'market-data');
        $this->assertStringContainsString('php artisan nepse:setup', $response->getContent());
    }

    #[Test]
    public function it_marks_the_portfolio_step_done_once_one_exists(): void
    {
        Portfolio::factory()->forUser($this->user)->default()->create(['name' => 'Main']);

        $response = $this->getJson('/api/onboarding')->assertOk();

        $this->assertTrue($this->step($response->json('data.steps'), 'portfolio')['done']);
        $this->assertSame('Main', $this->step($response->json('data.steps'), 'portfolio')['detail']);
    }

    #[Test]
    public function another_users_portfolio_does_not_count_as_yours(): void
    {
        Portfolio::factory()->forUser(User::factory()->create())->create();

        $response = $this->getJson('/api/onboarding')->assertOk();

        $this->assertFalse($this->step($response->json('data.steps'), 'portfolio')['done']);
    }

    #[Test]
    public function it_describes_the_selected_portfolio_and_never_someone_elses(): void
    {
        Portfolio::factory()->forUser($this->user)->default()->create(['name' => 'Main']);
        $second = Portfolio::factory()->forUser($this->user)->create(['name' => 'Side']);
        $foreign = Portfolio::factory()->forUser(User::factory()->create())->create(['name' => 'Theirs']);

        $steps = $this->getJson("/api/onboarding?portfolio_id={$second->id}")->assertOk()->json('data.steps');
        $this->assertSame('Side', $this->step($steps, 'portfolio')['detail']);

        // Another account's id is ignored, not honoured: back to the default.
        $steps = $this->getJson("/api/onboarding?portfolio_id={$foreign->id}")->assertOk()->json('data.steps');
        $this->assertSame('Main', $this->step($steps, 'portfolio')['detail']);
    }

    #[Test]
    public function classification_is_outstanding_only_when_there_is_something_to_classify(): void
    {
        $portfolio = Portfolio::factory()->forUser($this->user)->default()->create();

        // No transactions: nothing to classify, so this must not be presented
        // as a task. An empty portfolio would otherwise show a step whose only
        // possible action is to import something first.
        $response = $this->getJson('/api/onboarding')->assertOk();
        $this->assertFalse($this->step($response->json('data.steps'), 'channels')['done']);
        $this->assertNull($this->step($response->json('data.steps'), 'channels')['detail']);

        $stock = Stock::factory()->create();

        PortfolioTransaction::factory()->create([
            'portfolio_id' => $portfolio->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'acquisition_channel' => 'unknown',
        ]);

        /*
         * A sale carries no channel and the IPO/Traded screen never lists it.
         * Counting it here made the checklist report 95 outstanding against a
         * screen showing 65, so clearing every visible row still left the card
         * demanding work that did not exist.
         */
        PortfolioTransaction::factory()->create([
            'portfolio_id' => $portfolio->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'acquisition_channel' => 'unknown',
        ]);

        $response = $this->getJson('/api/onboarding')->assertOk();
        $channels = $this->step($response->json('data.steps'), 'channels');

        $this->assertFalse($channels['done']);
        $this->assertSame('1 of 1 still unclassified', $channels['detail']);

        PortfolioTransaction::query()->acquisitions()->update(['acquisition_channel' => 'secondary']);

        $response = $this->getJson('/api/onboarding')->assertOk();

        $this->assertTrue($this->step($response->json('data.steps'), 'channels')['done']);
    }

    #[Test]
    public function the_deposit_rate_step_completes_when_the_user_sets_their_own(): void
    {
        $this->assertFalse($this->step($this->getJson('/api/onboarding')->json('data.steps'), 'benchmark')['done']);

        $this->user->update(['benchmark_fd_rate' => 8.5]);

        $benchmark = $this->step($this->getJson('/api/onboarding')->json('data.steps'), 'benchmark');

        $this->assertTrue($benchmark['done']);
        $this->assertSame('8.5%', $benchmark['detail']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    private function step(array $steps, string $key): array
    {
        foreach ($steps as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }

        $this->fail("No onboarding step keyed \"{$key}\".");
    }
}
