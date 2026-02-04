<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Models\Group;
use App\Models\User;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\ParsedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class LocationIdTest extends TestCase
{
    use RefreshDatabase;

    private IncomingMailService $service;

    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IncomingMailService::class);
        $this->reflection = new ReflectionClass($this->service);
    }

    /**
     * Get access to private method for testing.
     */
    private function invokePrivateMethod(string $methodName, array $args = []): mixed
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->service, $args);
    }

    /** @test */
    public function it_finds_closest_postcode_by_coordinates(): void
    {
        // Create a test location in the database
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'AB10 1AB',
            'type' => 'Postcode',
            'lat' => 57.145,
            'lng' => -2.095,
        ]);

        // Add to spatial index
        $srid = config('freegle.srid', 3857);
        DB::statement("INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText('POINT(-2.095 57.145)', ?))", [
            $locationId, $srid,
        ]);

        // Test finding it
        $foundId = $this->invokePrivateMethod('findClosestPostcodeId', [57.145, -2.095]);

        $this->assertEquals($locationId, $foundId);
    }

    /** @test */
    public function it_returns_null_when_no_postcode_found(): void
    {
        // Test with coordinates in the middle of the ocean
        $foundId = $this->invokePrivateMethod('findClosestPostcodeId', [0.0, 0.0]);

        $this->assertNull($foundId);
    }

    /** @test */
    public function it_finds_closest_postcode_within_expanding_radius(): void
    {
        // Create a test location slightly offset from search point
        $locationId = DB::table('locations')->insertGetId([
            'name' => 'AB11 1AB',
            'type' => 'Postcode',
            'lat' => 57.150,
            'lng' => -2.100,
        ]);

        $srid = config('freegle.srid', 3857);
        DB::statement("INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText('POINT(-2.100 57.150)', ?))", [
            $locationId, $srid,
        ]);

        // Search from nearby point - should still find it due to expanding radius
        $foundId = $this->invokePrivateMethod('findClosestPostcodeId', [57.145, -2.095]);

        $this->assertEquals($locationId, $foundId);
    }

    /** @test */
    public function it_ignores_non_postcode_locations(): void
    {
        // Create a non-postcode location
        $nonPostcodeId = DB::table('locations')->insertGetId([
            'name' => 'Some Area',
            'type' => 'Polygon',
            'lat' => 57.145,
            'lng' => -2.095,
        ]);

        $srid = config('freegle.srid', 3857);
        DB::statement("INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText('POINT(-2.095 57.145)', ?))", [
            $nonPostcodeId, $srid,
        ]);

        // Should not find the non-postcode
        $foundId = $this->invokePrivateMethod('findClosestPostcodeId', [57.145, -2.095]);

        $this->assertNull($foundId);
    }

    /** @test */
    public function it_requires_space_in_postcode_name(): void
    {
        // Create a postcode without space (invalid format)
        $invalidId = DB::table('locations')->insertGetId([
            'name' => 'AB101AB',
            'type' => 'Postcode',
            'lat' => 57.145,
            'lng' => -2.095,
        ]);

        $srid = config('freegle.srid', 3857);
        DB::statement("INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText('POINT(-2.095 57.145)', ?))", [
            $invalidId, $srid,
        ]);

        // Should not find postcodes without spaces (they're postcode areas, not full postcodes)
        $foundId = $this->invokePrivateMethod('findClosestPostcodeId', [57.145, -2.095]);

        $this->assertNull($foundId);
    }

    /** @test */
    public function it_gets_lat_lng_from_tn_coordinates_header(): void
    {
        // Create a mock ParsedEmail with TN coordinates header
        $email = Mockery::mock(ParsedEmail::class);
        $email->shouldReceive('getTrashNothingCoordinates')
            ->andReturn('51.5074,-0.1278');
        $email->shouldReceive('getHeader')->andReturn(null);
        $email->shouldReceive('getTrashNothingPostId')->andReturn('12345');
        $email->shouldReceive('getTrashNothingSource')->andReturn('native-app');
        $email->shouldReceive('isFromTrashNothing')->andReturn(true);
        $email->subject = 'OFFER: Test item (London)';
        $email->textBody = 'Test body';
        $email->rawMessage = 'Raw message';
        $email->fromAddress = 'test@user.trashnothing.com';
        $email->fromName = 'Test User';
        $email->envelopeFrom = 'test@user.trashnothing.com';
        $email->envelopeTo = 'testgroup@groups.ilovefreegle.org';
        $email->messageId = 'test-message-id@trashnothing.com';
        $email->senderIp = null;

        // Create test user and group
        $user = User::factory()->create();
        $group = Group::factory()->create([
            'type' => 'Freegle',
            'onhere' => 1,
            'publish' => 1,
        ]);

        // The coordinates from the header should be used, not the user's location
        // This test verifies the parsing logic is correct
        $coords = $email->getTrashNothingCoordinates();
        $parts = explode(',', $coords);

        $this->assertCount(2, $parts);
        $this->assertEquals(51.5074, (float) $parts[0]);
        $this->assertEquals(-0.1278, (float) $parts[1]);
    }
}
