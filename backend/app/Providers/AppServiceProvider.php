<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AI\LlmClient;
use App\Services\AI\LlmManager;
use App\Services\Portfolio\TransactionCostCalculator;
use App\Services\Scraper\Contracts\HistorySource;
use App\Services\Scraper\HttpFetcher;
use App\Services\Scraper\PriceSourceManager;
use App\Services\Scraper\Sources\SharesansarChartSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One fetcher per request/command, so its per-host rate limiting sees
        // every call the pipeline makes rather than resetting each time.
        $this->app->singleton(HttpFetcher::class);

        // Also a singleton: sources memoise expensive per-run bootstrap work
        // (the market-wide company-id map, the session cookie and CSRF token),
        // and a fresh manager would re-fetch all of it on every resolution.
        $this->app->singleton(PriceSourceManager::class);

        $this->app->bind(
            HistorySource::class,
            fn ($app): HistorySource => new SharesansarChartSource($app->make(HttpFetcher::class)),
        );

        // Which model provider the analysis services get. Swapping AI_DRIVER is
        // the only change needed to move between the free and paid provider;
        // resolve LlmManager directly to reach a specific provider on demand.
        $this->app->singleton(LlmManager::class);

        $this->app->bind(LlmClient::class, fn ($app): LlmClient => $app->make(LlmManager::class)->default());

        // Built from the date-effective schedule in config/nepse.php rather
        // than default-constructed, so a quote uses the configured rates and
        // not the class's built-in fallbacks.
        $this->app->singleton(
            TransactionCostCalculator::class,
            static fn (): TransactionCostCalculator => TransactionCostCalculator::fromConfig(),
        );
    }

    public function boot(): void
    {
        // Catch typos in attribute names during development instead of silently
        // returning null from a mistyped property.
        Model::preventLazyLoading(false);
        Model::preventSilentlyDiscardingAttributes($this->app->isLocal());

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Behind a CDN/reverse proxy every request arrives from the proxy's IP, so
        // per-client rate limits would collapse into one shared bucket. Trust only
        // the listed proxy ranges to report the real client address.
        $proxies = config('nepse.trusted_proxies');
        if (is_array($proxies) && $proxies !== []) {
            TrustProxies::at($proxies);
        }
    }
}
