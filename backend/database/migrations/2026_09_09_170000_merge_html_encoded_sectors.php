<?php

declare(strict_types=1);

use App\Models\Sector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Collapse sectors whose names still carry HTML encoding.
 *
 * Merolagani files "Hotel &amp; Tourism" with the entity intact. No alias
 * matched its slug, so Sector::resolve() fell through to Str::title(), which
 * title-cases every word and rendered the entity as "&Amp;" — not a valid
 * entity, so it could never decode back. The result was a second
 * "Hotel &Amp; Tourism" row, splitting one sector's scrips across two
 * populations and drawing every peer percentile for the smaller group from the
 * wrong population. The same failure the hydropower merge repaired, from a
 * different cause.
 *
 * Sector::decodeName() now strips the encoding on the way in, case-insensitively
 * and repeatedly (these pages are sometimes double-encoded). This repairs what
 * was already stored.
 *
 * Re-runnable, and identical in shape to 2026_08_10_130000: it only moves
 * stocks off rows that still exist, and only deletes an empty row when
 * canonicalisation actually renames it.
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

        Log::info('Merged HTML-encoded sectors', ['stocks_moved' => $moved, 'sectors_dropped' => $dropped]);
    }

    public function down(): void
    {
        // Irreversible by design, as with the earlier merge: the encoded rows
        // carried no information the canonical row does not already hold.
    }
};
