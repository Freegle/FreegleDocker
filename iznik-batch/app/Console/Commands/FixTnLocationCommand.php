<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fix TN messages that have lat/lng but no locationid.
 *
 * messages_spatial uses point geometry from lat/lng directly, so no fix needed there.
 */
class FixTnLocationCommand extends Command
{
    protected $signature = 'tn:fix-locations
                            {--days=5 : Number of days to look back}
                            {--dry-run : Show what would be done without making changes}
                            {--limit=0 : Limit number of messages to process (0 = no limit)}';

    protected $description = 'Fix TN messages with lat/lng but no locationid';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info("Looking for TN messages with no locationid (last {$days} days)...");
        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be made');
        }

        $startDate = now()->subDays($days)->format('Y-m-d');

        // Get messages to fix
        $query = DB::table('messages')
            ->where('arrival', '>=', $startDate)
            ->whereNotNull('tnpostid')
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereNull('locationid')
            ->select('id', 'lat', 'lng');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $messages = $query->get();
        $this->info("Found {$messages->count()} messages to fix");

        $fixed = 0;
        $errors = 0;
        $srid = 3857;

        foreach ($messages as $msg) {
            // Use spatial index with expanding bounding box (like legacy closestPostcode)
            $location = $this->findLocationSpatial($msg->lat, $msg->lng, $srid);

            if ($location === null) {
                $errors++;
                continue;
            }

            if (!$dryRun) {
                // Simple update, no transaction - uses implicit row lock
                DB::update('UPDATE messages SET locationid = ? WHERE id = ?', [$location->locationid, $msg->id]);
            }

            $fixed++;

            if ($fixed % 500 === 0) {
                $this->line("  Processed {$fixed}...");
            }
        }

        $this->line('');
        $this->info('Summary:');
        $this->line("  Messages fixed: {$fixed}");
        $this->line("  Errors (no location found): {$errors}");

        Log::info('Fixed TN message locations', [
            'fixed' => $fixed,
            'errors' => $errors,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Find nearest postcode using spatial index with expanding bounding box.
     * Matches legacy Location::closestPostcode()
     */
    private function findLocationSpatial(float $lat, float $lng, int $srid): ?object
    {
        $scan = 0.00001953125; // Same starting value as legacy
        $maxScan = 0.2;

        while ($scan <= $maxScan) {
            $swlat = $lat - $scan;
            $nelat = $lat + $scan;
            $swlng = $lng - $scan;
            $nelng = $lng + $scan;

            $poly = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";

            // Match legacy: join locations, filter type=Postcode with space in name, order by distance
            $result = DB::selectOne("
                SELECT locations.id as locationid
                FROM locations_spatial
                INNER JOIN locations ON locations.id = locations_spatial.locationid
                WHERE MBRContains(ST_Envelope(ST_GeomFromText(?, ?)), locations_spatial.geometry)
                  AND locations.type = 'Postcode'
                  AND LOCATE(' ', locations.name) > 0
                ORDER BY ST_Distance(locations_spatial.geometry, ST_GeomFromText(?, ?)) ASC,
                         CASE WHEN ST_Dimension(locations_spatial.geometry) < 2 THEN 0
                              ELSE ST_Area(locations_spatial.geometry) END ASC
                LIMIT 1
            ", [$poly, $srid, "POINT($lng $lat)", $srid]);

            if ($result) {
                return $result;
            }

            $scan *= 2;
        }

        return null;
    }
}
