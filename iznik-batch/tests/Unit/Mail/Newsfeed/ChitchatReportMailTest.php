<?php

namespace Tests\Unit\Mail\Newsfeed;

use App\Mail\Newsfeed\ChitchatReportMail;
use Tests\TestCase;

class ChitchatReportMailTest extends TestCase
{
    public function test_subject_contains_reporter_info(): void
    {
        $mail = new ChitchatReportMail(
            reporterName: 'Jane Doe',
            reporterId: 42,
            reporterEmail: 'jane@example.com',
            newsfeedId: 123,
            reason: 'Spam content',
        );

        $envelope = $mail->envelope();
        $this->assertEquals(
            'Jane Doe #42 (jane@example.com) has reported a ChitChat thread',
            $envelope->subject
        );
    }

    public function test_from_address_is_geeks(): void
    {
        $mail = new ChitchatReportMail(
            reporterName: 'Test',
            reporterId: 1,
            reporterEmail: 'test@test.com',
            newsfeedId: 1,
            reason: 'Test',
        );

        $envelope = $mail->envelope();
        $this->assertEquals(config('freegle.mail.geeks_addr'), $envelope->from->address);
    }

    public function test_builds_with_mjml_template(): void
    {
        $mail = new ChitchatReportMail(
            reporterName: 'Test User',
            reporterId: 99,
            reporterEmail: 'test@test.com',
            newsfeedId: 456,
            reason: 'Inappropriate language',
        );

        // Build should not throw - verifies template exists and renders.
        $builtMail = $mail->build();
        $this->assertNotNull($builtMail);
    }

    public function test_sent_to_chitchat_support(): void
    {
        $mail = new ChitchatReportMail(
            reporterName: 'Reporter',
            reporterId: 10,
            reporterEmail: 'reporter@test.com',
            newsfeedId: 789,
            reason: 'Offensive',
        );

        $builtMail = $mail->build();

        // The mail should be addressed to the chitchat support address.
        $this->assertTrue($builtMail->hasTo(config('freegle.mail.chitchat_support_addr')));
    }
}
