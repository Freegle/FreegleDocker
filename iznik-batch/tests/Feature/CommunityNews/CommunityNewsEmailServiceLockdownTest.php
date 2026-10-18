<?php

namespace Tests\Feature\CommunityNews;

use App\Mail\CommunityNews\CommunityNewsMail;
use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Services\CommunityNews\CommunityNewsEmailService;
use App\Services\CommunityNews\CommunityNewsImageService;
use App\Services\GeminiService;
use App\Services\Lockdown\LockdownService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * CommunityNewsEmailService::sendWeekly() under the lockdown switch (plan
 * 2026-09-27-lockdown-switch.md, section 11.7). Ordinary (no lockdown) behaviour is
 * covered by CommunityNewsEmailServiceTest; this file is only the lockdown branch.
 *
 * The watermark here is per AREA, not per recipient: `items.emailed_at` and
 * `area.lastemailed` are only committed once the area's whole member loop finishes, so
 * the check goes once per area, before that area is touched at all - matching the
 * "check before each group" rule for a per-group watermark.
 */
class CommunityNewsEmailServiceLockdownTest extends TestCase
{
    private LockdownService $lockdown;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['freegle.mail.enabled_types' => 'CommunityNews']);

        $this->mock(CommunityNewsImageService::class, function ($mock) {
            $mock->shouldReceive('uploadItemImage')->andReturnNull();
            $mock->shouldReceive('deliveryUrl')->andReturnNull();
        });
        $this->mock(GeminiService::class, function ($mock) {
            $mock->shouldReceive('generateJson')->andReturnNull()->byDefault();
        });

        DB::table('lockdowns')->delete();
        DB::table('lockdown_counters')->delete();
        DB::table('lockdown_acks')->delete();
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
    }

    private function svc(): CommunityNewsEmailService
    {
        return app(CommunityNewsEmailService::class);
    }

    private function authority(int $id, string $name, float $lat, float $lng, float $delta = 0.05): int
    {
        $srid = (int) config('freegle.srid', 3857);
        $w = $lng - $delta;
        $e = $lng + $delta;
        $s = $lat - $delta;
        $n = $lat + $delta;

        DB::insert(
            'INSERT INTO authorities (id, name, polygon) VALUES (?, ?, ST_GeomFromText(?, ?))',
            [$id, $name, "POLYGON(($w $s, $e $s, $e $n, $w $n, $w $s))", $srid]
        );

        return $id;
    }

    private function locate($user, float $lat, float $lng): void
    {
        $settings = $user->settings ?? [];
        $settings['mylocation'] = ['lat' => $lat, 'lng' => $lng];
        $user->settings = $settings;
        $user->save();
    }

    private function setUpOneDueArea(): array
    {
        $authorityId = $this->authority(920101, 'Testville', 51.50, -0.12);

        $user = $this->createTestUser(['email_preferred' => 'member@test.com', 'newslettersallowed' => 1, 'bouncing' => 0]);
        $this->locate($user, 51.50, -0.12);

        $area = CommunityNewsArea::create([
            'authorityid' => $authorityId, 'name' => 'Testville', 'intro' => 'Hi',
            'lat' => 51.50, 'lng' => -0.12,
        ]);
        $item = CommunityNewsItem::create([
            'areaid' => $area->id, 'title' => 'T', 'snippet' => 'B',
            'url' => 'https://x.org', 'researched_at' => now(),
        ]);

        return [$area, $item, $user];
    }

    public function test_held_area_sends_nothing_and_leaves_its_watermark_untouched(): void
    {
        [$area, $item] = $this->setUpOneDueArea();

        $this->lockdown->press(null, 'test lockdown');

        $result = $this->svc()->sendWeekly();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['areas']);
        Mail::assertNothingSent();

        $this->assertNull($area->fresh()->lastemailed);
        $this->assertNull($item->fresh()->emailed_at);

        $counter = DB::table('lockdown_counters')->where('kind', 'deferred:communitynews')->first();
        $this->assertNotNull($counter);
        $this->assertSame(1, (int) $counter->count);

        $ack = DB::table('lockdown_acks')->where('loop', 'mail-loops')->first();
        $this->assertNotNull($ack);
        $this->assertEquals($this->lockdown->current()->id, $ack->lockdownrowid);
    }

    public function test_an_area_that_is_not_due_yet_is_not_deferred(): void
    {
        // lastemailed inside the min-days window means sendWeekly() returns before it
        // would ever reach the lockdown check for this area - nothing here to defer.
        [$area] = $this->setUpOneDueArea();
        $area->update(['lastemailed' => now()->subDay()]);

        $this->lockdown->press(null, 'test lockdown');

        $result = $this->svc()->sendWeekly();

        $this->assertSame(0, $result['areas']);
        $this->assertSame(0, DB::table('lockdown_counters')->where('kind', 'deferred:communitynews')->count());
    }

    public function test_lifting_email_sends_the_area_that_was_held(): void
    {
        [$area, $item] = $this->setUpOneDueArea();

        $this->lockdown->press(null, 'test lockdown');
        $held = $this->svc()->sendWeekly();
        $this->assertSame(0, $held['sent']);

        $this->lockdown->setSurfaces(['email' => false], null);

        $sent = $this->svc()->sendWeekly();
        $this->assertSame(1, $sent['sent']);
        Mail::assertSent(CommunityNewsMail::class, 1);
        $this->assertNotNull($area->fresh()->lastemailed);
        $this->assertNotNull($item->fresh()->emailed_at);
    }
}
