<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\CashPlacement;
use App\Models\CashTransaction;
use App\Models\Portfolio;
use App\Models\PortfolioHolding;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * The ownership boundary, end to end.
 *
 * Route model binding resolves a portfolio (and a holding, a cash transaction,
 * a placement) by id alone, so every one of these routes has to prove twice
 * over that it is safe: the portfolio belongs to the caller, and the nested id
 * belongs to *that* portfolio. Nothing else in the suite exercises either
 * check, which is precisely why one user reading another's money is the thing
 * these tests are here to stop.
 */
final class PortfolioAuthorizationTest extends FeatureTestCase
{
    private User $owner;

    private User $intruder;

    private Portfolio $portfolio;

    private Portfolio $otherPortfolio;

    private PortfolioHolding $holding;

    private CashTransaction $cashTransaction;

    private CashPlacement $placement;

    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();

        $this->portfolio = Portfolio::factory()->forUser($this->owner)->default()->create();
        $this->otherPortfolio = Portfolio::factory()->forUser($this->intruder)->default()->create();

        $this->stock = Stock::factory()->symbol('NABIL')->create();

        $this->holding = PortfolioHolding::factory()
            ->for($this->portfolio)
            ->for($this->stock)
            ->create();

        $this->cashTransaction = CashTransaction::factory()->for($this->portfolio)->create();
        $this->placement = CashPlacement::factory()->for($this->portfolio)->create();
    }

    /**
     * Every portfolio-scoped route, with a payload valid enough that a
     * rejection can only have come from the ownership check.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function portfolioScopedRoutes(): array
    {
        $today = Carbon::today()->toDateString();

        return [
            'show portfolio' => ['getJson', '/api/portfolios/{portfolio}', []],
            'update portfolio' => ['patchJson', '/api/portfolios/{portfolio}', ['name' => 'Renamed By Intruder']],
            'delete portfolio' => ['deleteJson', '/api/portfolios/{portfolio}', []],

            'list holdings' => ['getJson', '/api/portfolios/{portfolio}/holdings', []],
            'record transaction' => ['postJson', '/api/portfolios/{portfolio}/transactions', [
                'symbol' => 'NABIL',
                'type' => 'buy',
                'quantity' => 10,
                'price' => 500,
                'transacted_on' => $today,
            ]],
            'update holding' => ['patchJson', '/api/portfolios/{portfolio}/holdings/{holding}', [
                'notes' => 'changed',
            ]],
            'delete holding' => ['deleteJson', '/api/portfolios/{portfolio}/holdings/{holding}', []],
            'rebuild positions' => ['postJson', '/api/portfolios/{portfolio}/rebuild', []],

            'list cash' => ['getJson', '/api/portfolios/{portfolio}/cash', []],
            'record cash' => ['postJson', '/api/portfolios/{portfolio}/cash', [
                'action' => 'deposit',
                'account' => 'bank',
                'amount' => 1_000,
            ]],
            'amend cash' => ['patchJson', '/api/portfolios/{portfolio}/cash/{cash}', ['amount' => 999]],
            'void cash' => ['deleteJson', '/api/portfolios/{portfolio}/cash/{cash}', []],

            'list placements' => ['getJson', '/api/portfolios/{portfolio}/cash-placements', []],
            'create placement' => ['postJson', '/api/portfolios/{portfolio}/cash-placements', [
                'principal' => 1_000,
                'start_date' => $today,
            ]],
            'bulk create placements' => ['postJson', '/api/portfolios/{portfolio}/cash-placements/bulk', [
                'placements' => [['principal' => 1_000, 'start_date' => $today]],
            ]],
            'show placement' => ['getJson', '/api/portfolios/{portfolio}/cash-placements/{placement}', []],
            'update placement' => ['patchJson', '/api/portfolios/{portfolio}/cash-placements/{placement}', [
                'interest_rate' => 1,
            ]],
            'delete placement' => ['deleteJson', '/api/portfolios/{portfolio}/cash-placements/{placement}', []],
            'withdraw placement' => [
                'postJson',
                '/api/portfolios/{portfolio}/cash-placements/{placement}/withdraw',
                [],
            ],

            'position actions' => ['getJson', '/api/portfolios/{portfolio}/actions', []],
            'performance' => ['getJson', '/api/portfolios/{portfolio}/performance', []],
            'benchmark' => ['getJson', '/api/portfolios/{portfolio}/benchmark', []],
            'attribution' => ['getJson', '/api/portfolios/{portfolio}/attribution', []],
            'recommendations' => ['getJson', '/api/portfolios/{portfolio}/recommendations', []],
            'list imports' => ['getJson', '/api/portfolios/{portfolio}/imports', []],
        ];
    }

    // ------------------------------------------------------------------
    // Another user's portfolio
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('portfolioScopedRoutes')]
    public function another_user_is_refused_on_every_portfolio_scoped_route(
        string $method,
        string $path,
        array $payload,
    ): void {
        Sanctum::actingAs($this->intruder);

        $this->{$method}($this->resolve($path), $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'This portfolio belongs to another user.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('portfolioScopedRoutes')]
    public function an_unauthenticated_request_is_refused_with_json_not_a_redirect(
        string $method,
        string $path,
        array $payload,
    ): void {
        $response = $this->{$method}($this->resolve($path), $payload);

        $response->assertUnauthorized()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('message', 'Authentication required.');

        // An HTML login redirect would break the SPA's JSON-only client.
        $this->assertStringNotContainsString('<html', (string) $response->getContent());
    }

    // ------------------------------------------------------------------
    // Nested ids belonging to a different portfolio
    // ------------------------------------------------------------------

    #[Test]
    public function a_cash_transaction_from_another_portfolio_is_not_found_here(): void
    {
        Sanctum::actingAs($this->intruder);

        // The intruder owns $otherPortfolio, so ownership passes — the only
        // thing standing between them and someone else's ledger row is the
        // nested-id check.
        $this->patchJson(
            "/api/portfolios/{$this->otherPortfolio->id}/cash/{$this->cashTransaction->id}",
            ['amount' => 1],
        )
            ->assertNotFound()
            // bootstrap/app.php maps every api/* NotFoundHttpException to a
            // flat "Not found.", so the controllers' own 404 messages never
            // reach the client. That is the better behaviour and worth
            // asserting deliberately: echoing "not in this portfolio" would
            // confirm the record exists under some other portfolio, which is a
            // small information leak on an authorization boundary.
            ->assertJsonPath('message', 'Not found.');

        $this->deleteJson(
            "/api/portfolios/{$this->otherPortfolio->id}/cash/{$this->cashTransaction->id}"
        )->assertNotFound();

        // Untouched.
        $this->assertNull($this->cashTransaction->fresh()->voided_at);
        $this->assertNull($this->cashTransaction->fresh()->corrected_at);
    }

    #[Test]
    public function a_placement_from_another_portfolio_is_not_found_here(): void
    {
        Sanctum::actingAs($this->intruder);

        $base = "/api/portfolios/{$this->otherPortfolio->id}/cash-placements/{$this->placement->id}";

        $this->getJson($base)->assertNotFound();
        $this->patchJson($base, ['interest_rate' => 1])->assertNotFound();
        $this->postJson($base.'/withdraw')->assertNotFound();
        $this->deleteJson($base)->assertNotFound();

        $this->assertDatabaseHas('cash_placements', [
            'id' => $this->placement->id,
            'portfolio_id' => $this->portfolio->id,
        ]);
    }

    #[Test]
    public function a_holding_from_another_portfolio_is_not_found_here(): void
    {
        Sanctum::actingAs($this->intruder);

        $base = "/api/portfolios/{$this->otherPortfolio->id}/holdings/{$this->holding->id}";

        $this->patchJson($base, ['notes' => 'changed'])
            ->assertNotFound()
            // bootstrap/app.php maps every api/* NotFoundHttpException to a
            // flat "Not found.", so the controllers' own 404 messages never
            // reach the client. That is the better behaviour and worth
            // asserting deliberately: echoing "not in this portfolio" would
            // confirm the record exists under some other portfolio, which is a
            // small information leak on an authorization boundary.
            ->assertJsonPath('message', 'Not found.');

        $this->deleteJson($base)->assertNotFound();

        $this->assertDatabaseHas('portfolio_holdings', [
            'id' => $this->holding->id,
            'portfolio_id' => $this->portfolio->id,
        ]);
    }

    #[Test]
    public function an_id_that_does_not_exist_at_all_is_a_json_404(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/portfolios/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Not found.');

        $this->getJson("/api/portfolios/{$this->portfolio->id}/cash-placements/999999")
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Listings never cross the owner boundary
    // ------------------------------------------------------------------

    #[Test]
    public function the_portfolio_listing_only_shows_the_callers_own(): void
    {
        Sanctum::actingAs($this->intruder);

        $this->getJson('/api/portfolios')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->otherPortfolio->id);
    }

    #[Test]
    public function an_admin_may_inspect_any_portfolio(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/portfolios/{$this->portfolio->id}")->assertOk();
        $this->getJson("/api/portfolios/{$this->portfolio->id}/cash")->assertOk();
        $this->getJson("/api/portfolios/{$this->portfolio->id}/cash-placements")->assertOk();
    }

    /**
     * Authorization must not be observable through validation.
     *
     * A FormRequest validates before the controller method is entered, so
     * while ownership was only checked inside the controller, hitting another
     * user's portfolio with an invalid body answered 422 — which confirms the
     * portfolio id exists before authorization has been evaluated. Ownership
     * now runs as route middleware, ahead of validation, so the answer is 403
     * regardless of how bad the payload is.
     */
    #[Test]
    public function an_invalid_body_still_gets_403_not_422_on_someone_elses_portfolio(): void
    {
        $this->actingAs($this->owner);

        $cases = [
            ['patch', "/api/portfolios/{$this->otherPortfolio->id}", ['name' => '']],
            ['post', "/api/portfolios/{$this->otherPortfolio->id}/transactions", ['type' => 'nonsense']],
            ['post', "/api/portfolios/{$this->otherPortfolio->id}/cash", ['action' => 'nonsense']],
            ['post', "/api/portfolios/{$this->otherPortfolio->id}/cash-placements", ['principal' => -1]],
        ];

        foreach ($cases as [$verb, $path, $payload]) {
            $this->{$verb.'Json'}($path, $payload)
                ->assertStatus(403)
                ->assertJsonPath('message', 'This portfolio belongs to another user.');
        }
    }

    private function resolve(string $path): string
    {
        return str_replace(
            ['{portfolio}', '{holding}', '{cash}', '{placement}'],
            [
                (string) $this->portfolio->id,
                (string) $this->holding->id,
                (string) $this->cashTransaction->id,
                (string) $this->placement->id,
            ],
            $path,
        );
    }
}
