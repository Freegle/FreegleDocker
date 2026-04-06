<?php

namespace Tests\Unit\Services;

use App\Services\GiftAidClaimService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GiftAidClaimServiceTest extends TestCase
{
    protected GiftAidClaimService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GiftAidClaimService();
    }

    // -------------------------------------------------------------------------
    // splitName tests
    // -------------------------------------------------------------------------

    public function test_split_name_uses_dedicated_columns_when_both_set(): void
    {
        [$first, $last] = $this->service->splitName('John Smith', 'Jon', 'Smyth');
        $this->assertEquals('Jon', $first);
        $this->assertEquals('Smyth', $last);
    }

    public function test_split_name_falls_back_when_dedicated_columns_null(): void
    {
        [$first, $last] = $this->service->splitName('Jane Doe', null, null);
        $this->assertEquals('Jane', $first);
        $this->assertEquals('Doe', $last);
    }

    public function test_split_name_falls_back_when_firstname_empty(): void
    {
        [$first, $last] = $this->service->splitName('Jane Doe', '', 'Doe');
        $this->assertEquals('Jane', $first);
        $this->assertEquals('Doe', $last);
    }

    public function test_split_name_falls_back_when_lastname_empty(): void
    {
        [$first, $last] = $this->service->splitName('Jane Doe', 'Jane', '');
        $this->assertEquals('Jane', $first);
        $this->assertEquals('Doe', $last);
    }

    public function test_split_name_handles_multiple_word_last_name(): void
    {
        [$first, $last] = $this->service->splitName('Maria Garcia Lopez', null, null);
        $this->assertEquals('Maria', $first);
        $this->assertEquals('Garcia Lopez', $last);
    }

    public function test_split_name_returns_empty_last_name_for_mononym(): void
    {
        [$first, $last] = $this->service->splitName('Sukarno', null, null);
        $this->assertEquals('Sukarno', $first);
        $this->assertEquals('', $last);
    }

    // -------------------------------------------------------------------------
    // generateClaim dry run tests
    // -------------------------------------------------------------------------

    public function test_generate_claim_outputs_header_and_row(): void
    {
        $user = $this->createTestUser();

        // Create a reviewed gift aid declaration
        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, postcode, housenameornumber, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'John Smith', '1 Test St, London', 'SW1A 1AA', '1', NOW(), NOW())",
            [$user->id]
        );

        $giftaidId = DB::table('giftaid')->where('userid', $user->id)->value('id');

        // Create a claimable donation
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 10.00, 1, NULL, 'Stripe', NOW(), 'test@example.com')",
            [$user->id]
        );

        $rows = [];
        $result = $this->service->generateClaim(
            dryRun: true,
            rowCallback: function (array $row) use (&$rows) {
                $rows[] = $row;
            }
        );

        // Should have header + 1 data row
        $this->assertCount(2, $rows);
        $this->assertEquals('First name or initial', $rows[0][1]);
        $this->assertEquals('John', $rows[1][1]);
        $this->assertEquals('Smith', $rows[1][2]);
        $this->assertEquals(1, $result['rows']);
        $this->assertEquals(0, $result['invalid']);
        $this->assertEquals(10.0, $result['total']);

        // Dry run: donation should NOT be marked as claimed
        $claimed = DB::table('users_donations')
            ->where('userid', $user->id)
            ->value('giftaidclaimed');
        $this->assertNull($claimed);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_donations WHERE userid = ?', [$user->id]);
    }

    public function test_generate_claim_marks_donations_claimed_when_not_dry_run(): void
    {
        $user = $this->createTestUser();

        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, postcode, housenameornumber, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Jane Brown', '5 High St', 'EC1A 1BB', '5', NOW(), NOW())",
            [$user->id]
        );

        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 25.00, 1, NULL, 'DonateWithPayPal', NOW(), 'jane@example.com')",
            [$user->id]
        );

        $result = $this->service->generateClaim(dryRun: false);

        $this->assertGreaterThanOrEqual(1, $result['rows']);

        // Donation should now be marked claimed
        $claimed = DB::table('users_donations')
            ->where('userid', $user->id)
            ->whereNotNull('giftaidclaimed')
            ->exists();
        $this->assertTrue($claimed);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_donations WHERE userid = ?', [$user->id]);
    }

    public function test_generate_claim_uses_firstname_lastname_columns_when_set(): void
    {
        $user = $this->createTestUser();

        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, firstname, lastname, homeaddress, postcode, housenameornumber, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Firstname Lastname', 'Budi', 'Santoso', '7 Test Road', 'W1A 0AX', '7', NOW(), NOW())",
            [$user->id]
        );

        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 5.00, 1, NULL, 'Stripe', NOW(), 'budi@example.com')",
            [$user->id]
        );

        $rows = [];
        $this->service->generateClaim(
            dryRun: true,
            rowCallback: function (array $row) use (&$rows) {
                $rows[] = $row;
            }
        );

        // Header + 1 data row
        $this->assertCount(2, $rows);
        $this->assertEquals('Budi', $rows[1][1]);
        $this->assertEquals('Santoso', $rows[1][2]);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_donations WHERE userid = ?', [$user->id]);
    }

    public function test_generate_claim_invalidates_record_without_house_number(): void
    {
        $user = $this->createTestUser();

        // No housenameornumber and no postcode pattern that can be extracted
        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Ann Lee', 'Flat unknown, nowhere', NOW(), NOW())",
            [$user->id]
        );

        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 15.00, 1, NULL, 'Stripe', NOW(), 'ann@example.com')",
            [$user->id]
        );

        $result = $this->service->generateClaim(dryRun: false);

        $this->assertEquals(1, $result['invalid']);
        $this->assertEquals(0, $result['rows']);

        // reviewed should now be NULL (reset for review)
        $reviewed = DB::table('giftaid')
            ->where('userid', $user->id)
            ->value('reviewed');
        $this->assertNull($reviewed);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_donations WHERE userid = ?', [$user->id]);
    }

    public function test_generate_claim_skips_duplicate_donations_same_amount_same_day(): void
    {
        $user = $this->createTestUser();

        DB::insert(
            "INSERT INTO giftaid (userid, period, fullname, homeaddress, postcode, housenameornumber, reviewed, timestamp)
             VALUES (?, 'Past4YearsAndFuture', 'Tom Jones', '3 Main Rd', 'CF10 1AA', '3', NOW(), NOW())",
            [$user->id]
        );

        // Two identical donations on same day — only one should appear in output
        $today = now()->format('Y-m-d H:i:s');
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 20.00, 1, NULL, 'Stripe', ?, 'tom@example.com')",
            [$user->id, $today]
        );
        DB::insert(
            "INSERT INTO users_donations (userid, GrossAmount, giftaidconsent, giftaidclaimed, source, timestamp, Payer)
             VALUES (?, 20.00, 1, NULL, 'Stripe', ?, 'tom@example.com')",
            [$user->id, $today]
        );

        $rows = [];
        $result = $this->service->generateClaim(
            dryRun: true,
            rowCallback: function (array $row) use (&$rows) {
                $rows[] = $row;
            }
        );

        // header + 1 data row (duplicate skipped)
        $this->assertCount(2, $rows);
        $this->assertEquals(1, $result['rows']);

        // Cleanup
        DB::delete('DELETE FROM giftaid WHERE userid = ?', [$user->id]);
        DB::delete('DELETE FROM users_donations WHERE userid = ?', [$user->id]);
    }
}
