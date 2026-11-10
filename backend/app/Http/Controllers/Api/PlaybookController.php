<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\InvestorProfile;
use App\Enums\VerdictGrade;
use App\Http\Controllers\Controller;
use App\Models\ProfileVerdict;
use App\Models\Stock;
use App\Services\Playbook\PlaybookService;
use App\Services\Playbook\PlaybookVerdict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The rule-based, profile-aware read on the market.
 *
 * Free to call: everything here is served from stored verdicts or recomputed
 * from indicators already in the database, so it sits in the public read tier
 * alongside the price endpoints rather than the AI tier.
 */
class PlaybookController extends Controller
{
    public function __construct(private readonly PlaybookService $playbook) {}

    /**
     * The profile catalog — what each profile is and how it behaves.
     *
     * Served from the enum so the UI never hardcodes a hold band or a stop
     * width that the engine might later be retuned away from.
     */
    public function profiles(): JsonResponse
    {
        return response()->json([
            'data' => array_map(static function (InvestorProfile $profile): array {
                [$targetOne, $targetTwo] = $profile->targetBands();

                return [
                    'value' => $profile->value,
                    'label' => $profile->label(),
                    'short_label' => $profile->shortLabel(),
                    'description' => $profile->description(),
                    'hold_label' => $profile->holdLabel(),
                    'time_horizon' => $profile->timeHorizon()->value,
                    'target_bands' => [round($targetOne * 100, 1), round($targetTwo * 100, 1)],
                    'max_stop_loss_percent' => round($profile->maxStopLoss() * 100, 1),
                    'max_position_weight_percent' => round($profile->maxPositionWeight() * 100, 1),
                    'weights' => $profile->weights(),
                ];
            }, InvestorProfile::cases()),
        ]);
    }

    /**
     * Rank the market for one profile — the screen that answers "what should
     * someone like me be buying today".
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profile' => ['nullable', Rule::enum(InvestorProfile::class)],
            'grade' => ['nullable', Rule::enum(VerdictGrade::class)],
            'in_zone' => ['nullable', 'in:0,1,true,false'],
            'sector' => ['nullable', 'string', 'max:120'],
            'min_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $profile = InvestorProfile::tryFrom((string) ($validated['profile'] ?? '')) ?? InvestorProfile::default();

        $query = ProfileVerdict::query()
            ->with(['stock:id,symbol,name,sector_id,last_price,change_percent,instrument_type', 'stock.sector:id,name'])
            ->forProfile($profile)
            ->latestPerProfile()
            ->where('analysis_date', '>=', $this->freshnessCutoff())
            ->whereHas('stock', fn ($stocks) => $stocks
                ->where('is_active', true)
                ->where('last_price', '>', 0)
                ->where('last_traded_on', '>=', $this->freshnessCutoff()));

        if (isset($validated['grade'])) {
            $query->where('grade', $validated['grade']);
        } else {
            // Default to the half of the market worth a look, not all 350 rows.
            $query->actionable();
        }

        if ($request->boolean('in_zone')) {
            $query->whereHas('stock', static fn ($stocks) => $stocks
                ->whereColumn('stocks.last_price', '>=', 'profile_verdicts.entry_low')
                ->whereColumn('stocks.last_price', '<=', 'profile_verdicts.entry_high'));
        }

        if (isset($validated['min_score'])) {
            $query->where('score', '>=', (float) $validated['min_score']);
        }

        if (isset($validated['sector'])) {
            $sector = $validated['sector'];
            $query->whereHas('stock.sector', static fn ($q) => $q->where('name', $sector));
        }

        $verdicts = $query
            ->orderByDesc('score')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString();

        return response()->json([
            'data' => collect($verdicts->items())->map(fn (ProfileVerdict $v): array => $this->present($v))->all(),
            'meta' => [
                'profile' => $profile->value,
                'profile_label' => $profile->label(),
                'current_page' => $verdicts->currentPage(),
                'last_page' => $verdicts->lastPage(),
                'per_page' => $verdicts->perPage(),
                'total' => $verdicts->total(),
            ],
        ]);
    }

    /**
     * All three profiles' read on one scrip.
     *
     * Recomputed live so the zones track today's close even if the nightly
     * playbook run has not happened since the last scrape, with the stored
     * verdict's date reported alongside for transparency.
     */
    public function forStock(Request $request, Stock $stock): JsonResponse
    {
        $fresh = $stock->is_active && (float) $stock->last_price > 0
            && $stock->getRawOriginal('last_traded_on') !== null
            && Carbon::parse($stock->getRawOriginal('last_traded_on'))->gte($this->freshnessCutoff());
        $live = $fresh ? $this->playbook->evaluateLive($stock) : [];
        $stored = ProfileVerdict::query()
            ->where('stock_id', $stock->id)
            ->where('analysis_date', '>=', $this->freshnessCutoff())
            ->latestPerProfile()
            ->get()
            ->keyBy(static fn (ProfileVerdict $v): string => $v->investor_profile->value);

        $data = [];

        foreach (InvestorProfile::cases() as $profile) {
            $verdict = $live[$profile->value] ?? null;

            if ($verdict instanceof PlaybookVerdict) {
                $data[] = $verdict->toArray() + [
                    'source' => 'live',
                    'stored_on' => $stored->get($profile->value)?->analysis_date?->toDateString(),
                ];

                continue;
            }

            $fallback = $fresh ? $stored->get($profile->value) : null;
            if ($fallback !== null) {
                $fallback->setRelation('stock', $stock);
            }

            $data[] = $fallback === null
                // Not enough history for this profile to have an opinion. Saying
                // so beats an empty card the user cannot interpret.
                ? [
                    'profile' => $profile->value,
                    'profile_label' => $profile->shortLabel(),
                    'grade' => null,
                    'summary' => ! $fresh ? 'A current, valid traded price is unavailable. Analysis will resume when market data is refreshed.' : sprintf('Not enough price history to judge this as a %s — %d sessions needed.',
                        strtolower($profile->label()), $profile->minHistorySessions()),
                    'source' => 'unavailable',
                ]
                : $this->present($fallback) + ['source' => 'stored'];
        }

        return response()->json([
            'data' => [
                'symbol' => $stock->symbol,
                'last_price' => $stock->last_price === null ? null : (float) $stock->last_price,
                'profiles' => $data,
            ],
        ]);
    }

    private function freshnessCutoff(): string
    {
        return Carbon::today()->subDays(max(1, (int) config('nepse.analysis.max_price_age_days', 7)))->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ProfileVerdict $verdict): array
    {
        $stock = $verdict->relationLoaded('stock') ? $verdict->stock : null;
        if (! $stock instanceof Stock) {
            $stock = null;
        }

        return [
            'profile' => $verdict->investor_profile->value,
            'profile_label' => $verdict->investor_profile->shortLabel(),
            'grade' => $verdict->grade->value,
            'grade_label' => $verdict->grade->label(),
            'score' => $verdict->score,
            'analysis_date' => $verdict->analysis_date->toDateString(),
            'reference_price' => $verdict->reference_price,
            'entry_low' => $verdict->entry_low,
            'entry_high' => $verdict->entry_high,
            'stop_loss' => $verdict->stop_loss,
            'target_1' => $verdict->target_1,
            'target_2' => $verdict->target_2,
            'risk_reward' => $verdict->risk_reward,
            'price_in_zone' => $stock !== null
                ? ((float) $stock->last_price >= $verdict->entry_low
                    && (float) $stock->last_price <= $verdict->entry_high)
                : $verdict->price_in_zone,
            'hold_label' => $verdict->investor_profile->holdLabel(),
            'scores' => $verdict->scores,
            'reasons' => $verdict->reasons,
            'summary' => $verdict->summary,
            'stock' => $stock !== null ? [
                'symbol' => $stock->symbol,
                'name' => $stock->name,
                'sector' => $stock->sector?->name,
                'last_price' => $stock->last_price === null ? null : (float) $stock->last_price,
                'change_percent' => $stock->change_percent === null ? null : (float) $stock->change_percent,
                'instrument_type' => $stock->instrument_type,
            ] : null,
        ];
    }
}
