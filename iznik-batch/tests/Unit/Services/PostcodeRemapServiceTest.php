<?php

namespace Tests\Unit\Services;

use App\Services\PostcodeRemapService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostcodeRemapServiceTest extends TestCase
{
    private PostcodeRemapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PostcodeRemapService::class);
    }

    public function test_remap_returns_zero_when_postgres_unavailable(): void
    {
        // Override the pgsql config to point to a non-existent host.
        config(['database.connections.pgsql.host' => 'nonexistent-host-that-does-not-exist']);
        DB::purge('pgsql');

        $result = $this->service->remapPostcodes(NULL);
        $this->assertEquals(0, $result);
    }

    public function test_remap_postcodes_task_dispatches_correctly(): void
    {
        // Ensure background_tasks table exists.
        DB::statement('CREATE TABLE IF NOT EXISTS background_tasks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_type VARCHAR(50) NOT NULL,
            data JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at TIMESTAMP NULL,
            failed_at TIMESTAMP NULL,
            error_message TEXT NULL,
            attempts INT UNSIGNED DEFAULT 0,
            INDEX idx_task_type (task_type),
            INDEX idx_pending (processed_at, created_at)
        )');

        // Insert a remap_postcodes task like Go would.
        $polygon = 'POLYGON((-3.22 55.93, -3.22 55.98, -3.17 55.98, -3.17 55.93, -3.22 55.93))';
        DB::table('background_tasks')->insert([
            'task_type' => 'remap_postcodes',
            'data' => json_encode([
                'location_id' => 12345,
                'polygon' => $polygon,
            ]),
            'created_at' => now(),
        ]);

        $task = DB::table('background_tasks')
            ->where('task_type', 'remap_postcodes')
            ->first();

        $this->assertNotNull($task);
        $data = json_decode($task->data, TRUE);
        $this->assertEquals(12345, $data['location_id']);
        $this->assertEquals($polygon, $data['polygon']);

        // Clean up.
        DB::table('background_tasks')->truncate();
    }

    public function test_find_nearest_area_with_postgres(): void
    {
        // Skip if PostgreSQL is not available.
        try {
            DB::connection('pgsql')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not available');
        }

        // Set up PostGIS extensions and locations table.
        $pgsql = DB::connection('pgsql');
        $pgsql->statement('CREATE EXTENSION IF NOT EXISTS postgis');
        $pgsql->statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        $typeExists = $pgsql->selectOne("SELECT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'location_type')");
        if (! $typeExists->exists) {
            $pgsql->statement("CREATE TYPE location_type AS ENUM('Road','Polygon','Line','Point','Postcode')");
        }

        $pgsql->statement('DROP TABLE IF EXISTS locations');
        $pgsql->statement('CREATE TABLE locations (
            id serial PRIMARY KEY,
            locationid bigint UNIQUE NOT NULL,
            name text,
            type location_type,
            area numeric,
            location geometry
        )');
        $pgsql->statement('CREATE INDEX idx_locations_location ON locations USING gist (location)');

        $srid = (int) config('freegle.srid', 3857);

        // Insert a test area polygon (roughly Edinburgh area).
        $polygon = 'POLYGON((-3.25 55.90, -3.25 56.00, -3.15 56.00, -3.15 55.90, -3.25 55.90))';
        $pgsql->insert(
            "INSERT INTO locations (locationid, name, type, area, location)
             VALUES (?, ?, 'Polygon', ST_Area(ST_GeomFromText(?, ?)), ST_GeomFromText(?, ?))",
            [99999, 'Test Area Edinburgh', $polygon, $srid, $polygon, $srid]
        );

        // Query for a point inside the polygon.
        $result = $this->service->findNearestArea(-3.20, 55.95);
        $this->assertEquals(99999, $result);

        // Query for a point far away — should still find nearest but with larger buffer.
        $farResult = $this->service->findNearestArea(0.0, 51.5);
        // May or may not find it depending on buffer size — just test it doesn't crash.
        $this->assertTrue($farResult === NULL || $farResult === 99999);

        // Clean up.
        $pgsql->statement('DROP TABLE IF EXISTS locations');
    }
}
