<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\ClaudeApiException;
use App\Services\AI\Prompts\ScripComparisonPrompt;
use App\Services\Analysis\ScripComparator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AI verdict on a head-to-head comparison.
 *
 * Split cleanly from {@see ScripComparator}: the comparator does the arithmetic
 * and the counting, deterministically and for free, and this adds the weighting
 * judgement that a count cannot express. Anyone who only wants the table can
 * call the comparator and never spend a token.
 *
 * Uses the deep tier — this is a low-volume, high-reasoning call, unlike the
 * per-stock signal sweep.
 */
final class ScripComparisonAnalyzer
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ScripComparisonPrompt $prompt,
        private readonly ScripComparator $comparator,
    ) {}

    /**
     * @param  list<string>  $symbols
     * @return array<string, mixed>
     *
     * @throws ClaudeApiException
     */
    public function analyze(array $symbols): array
    {
        $comparison = $this->comparator->compare($symbols);

        // Include every compared input: prices and corrected filing values may
        // change within the same quarter. A cached verdict must describe the
        // exact table the visitor sees.
        $key = 'compare:ai:'.implode('|', $comparison['symbols']).':'.$this->inputFingerprint($comparison);

        return Cache::remember($key, now()->addHours(12), function () use ($comparison): array {
            $result = $this->llm->structured(
                systemPrompt: $this->prompt->system(),
                userPrompt: $this->prompt->user($comparison),
                toolName: 'record_comparison',
                toolDescription: 'Record the head-to-head verdict, the metrics that drove it, and the case for and against each scrip.',
                schema: $this->prompt->schema(),
                tier: LlmClient::TIER_DEEP,
            );

            $verdict = $this->sanitize($result['data'], $comparison);

            Log::channel('analysis')->info('Scrip comparison analysed', [
                'symbols' => $comparison['symbols'],
                'counted_winner' => $comparison['verdict']['winner'],
                'model_winner' => $verdict['winner'],
                'agrees' => $verdict['agrees_with_tally'],
                'model' => $result['model'],
            ]);

            return [
                'comparison' => $comparison,
                'verdict' => $verdict,
                'model' => $result['model'],
            ];
        });
    }

    /**
     * Keep the model inside the facts it was given.
     *
     * A model that names a winner outside the compared set, or claims agreement
     * with a tally it in fact contradicts, would put a plausible-sounding but
     * unsupported verdict in front of the user. Both are cheap to detect here
     * and impossible to detect downstream.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $comparison
     * @return array<string, mixed>
     */
    private function sanitize(array $data, array $comparison): array
    {
        $symbols = $comparison['symbols'];
        $winner = is_string($data['winner'] ?? null) ? strtoupper(trim($data['winner'])) : 'NONE';

        if (! in_array($winner, $symbols, true)) {
            $winner = null;
        }

        $countedWinner = $comparison['verdict']['winner'];
        $actuallyAgrees = $winner === $countedWinner;

        $confidence = (int) ($data['confidence'] ?? 50);
        $confidence = max(0, min(100, $confidence));

        return [
            'winner' => $winner,
            'confidence' => $confidence,
            // Recomputed rather than trusted: the flag is a fact about two
            // values we both hold, not something the model gets to assert.
            'agrees_with_tally' => $actuallyAgrees,
            'counted_winner' => $countedWinner,
            'disagreement_reason' => $actuallyAgrees
                ? null
                : (is_string($data['disagreement_reason'] ?? null) ? $data['disagreement_reason'] : null),
            'summary' => is_string($data['summary'] ?? null) ? $data['summary'] : '',
            'decisive_metrics' => $this->cleanDecisive($data['decisive_metrics'] ?? [], $symbols),
            'per_scrip' => is_array($data['per_scrip'] ?? null) ? array_values($data['per_scrip']) : [],
            'data_gaps' => is_array($data['data_gaps'] ?? null) ? array_values($data['data_gaps']) : [],
        ];
    }

    /**
     * @param  list<string>  $symbols
     * @return list<array<string, mixed>>
     */
    private function cleanDecisive(mixed $rows, array $symbols): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['metric'])) {
                continue;
            }

            $favours = is_string($row['favours'] ?? null) ? strtoupper(trim($row['favours'])) : null;

            $out[] = [
                'metric' => (string) $row['metric'],
                'favours' => in_array($favours, $symbols, true) ? $favours : null,
                'why_it_matters' => (string) ($row['why_it_matters'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $comparison
     */
    private function inputFingerprint(array $comparison): string
    {
        return hash('sha256', serialize($comparison));
    }
}
