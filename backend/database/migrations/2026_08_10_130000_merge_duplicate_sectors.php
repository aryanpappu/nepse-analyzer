<?php

declare(strict_types=1);

use App\Models\Sector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Collapse sectors that two sources spelled differently.
 *
 * Merolagani files "Hydro Power", "Tradings" and "Promotor Share"; ShareSansar
 * files "Hydropower", "Trading" and "Promoter Share". Each pair had been
 * resolving to its own row, which split the 103-scrip hydropower sector into
 * groups of 103 and 7 — and every peer percentile computed for those seven was
 * drawn from the wrong population.
 *
 * Sector::resolve now canonicalises on the way in; this repairs what was already
 * stored. Re-runnable: it only moves stocks off rows that still exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $moved = 0;
        $dropped = 0;

        foreach (Sector::all() as $sector) {
            $canonical = Sector::canonicalName($sector->name);

            if ($canonical === null || $canonical === $sector->name) {
                continue;
            }

            $target = Sector::firstOrCreate(
                ['slug' => Str::slug($canonical)],
                ['name' => $canonical],
            );

            if ($target->id === $sector->id) {
                continue;
            }

            $moved += DB::table('stocks')
                ->where('sector_id', $sector->id)
                ->update(['sector_id' => $target->id]);

            $sector->delete();
            $dropped++;
        }

        // Empty duplicates left over from earlier crawls carry no stocks but do
        // show up in the sector listing, so clear them too — but only the ones
        // an alias identifies, never a genuinely empty real sector.
        foreach (Sector::withCount('stocks')->get() as $sector) {
            if ($sector->stocks_count > 0) {
                continue;
            }

            $canonical = Sector::canonicalName($sector->name);

            if ($canonical !== null && $canonical !== $sector->name) {
                $sector->delete();
                $dropped++;
            }
        }

        Log::info('Merged duplicate sectors', ['stocks_moved' => $moved, 'sectors_dropped' => $dropped]);
    }

    public function down(): void
    {
        // Irreversible by design: the alias rows carried no information beyond
        // the misspelling, so there is nothing to restore them from.
    }
};
