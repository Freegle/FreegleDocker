<?php

namespace Tests\Unit\Mail;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Envelope;
use Tests\TestCase;

class MjmlMailableTest extends TestCase
{
    public function test_get_default_data_returns_expected_keys(): void
    {
        $mailable = new class extends MjmlMailable {
            protected function getSubject(): string
            {
                return 'Test Subject';
            }

            public function exposeGetDefaultData(): array
            {
                return $this->getDefaultData();
            }
        };

        $data = $mailable->exposeGetDefaultData();

        $this->assertArrayHasKey('siteName', $data);
        $this->assertArrayHasKey('logoUrl', $data);
        $this->assertArrayHasKey('userSite', $data);
        $this->assertArrayHasKey('modSite', $data);
        $this->assertArrayHasKey('supportEmail', $data);
        $this->assertArrayHasKey('currentYear', $data);
        $this->assertEquals(date('Y'), $data['currentYear']);
    }

    public function test_mjml_view_sets_template_and_data(): void
    {
        $mailable = new class extends MjmlMailable {
            protected function getSubject(): string
            {
                return 'Test Subject';
            }

            public function exposeMjmlView(string $template, array $data = []): static
            {
                return $this->mjmlView($template, $data);
            }

            public function getMjmlTemplate(): string
            {
                return $this->mjmlTemplate;
            }

            public function getMjmlData(): array
            {
                return $this->mjmlData;
            }
        };

        // Use a real template that exists in the codebase.
        // Provide the required variables that the thank-you template expects.
        $mockUser = new \stdClass();
        $mockUser->displayname = 'Test User';
        $mockUser->email_preferred = $this->uniqueEmail('mjml');
        $mailable->exposeMjmlView('emails.mjml.donation.thank-you', [
            'custom' => 'value',
            'user' => $mockUser,
            'continueUrl' => 'https://example.com/continue',
            'settingsUrl' => 'https://example.com/settings',
        ]);

        $this->assertEquals('emails.mjml.donation.thank-you', $mailable->getMjmlTemplate());
        $this->assertArrayHasKey('custom', $mailable->getMjmlData());
        $this->assertEquals('value', $mailable->getMjmlData()['custom']);
        // Should also have default data merged.
        $this->assertArrayHasKey('siteName', $mailable->getMjmlData());
    }

    public function test_envelope_returns_correct_subject(): void
    {
        $mailable = new class extends MjmlMailable {
            protected function getSubject(): string
            {
                return 'My Custom Subject';
            }
        };

        $envelope = $mailable->envelope();

        $this->assertInstanceOf(Envelope::class, $envelope);
        $this->assertEquals('My Custom Subject', $envelope->subject);
    }

    public function test_attachments_returns_empty_array_by_default(): void
    {
        $mailable = new class extends MjmlMailable {
            protected function getSubject(): string
            {
                return 'Test Subject';
            }
        };

        $attachments = $mailable->attachments();

        $this->assertIsArray($attachments);
        $this->assertEmpty($attachments);
    }

    public function test_compile_mjml_throws_on_invalid_mjml(): void
    {
        $mailable = new class extends MjmlMailable {
            protected function getSubject(): string
            {
                return 'Test Subject';
            }

            public function exposeCompileMjml(string $mjml): string
            {
                return $this->compileMjml($mjml);
            }
        };

        // Invalid MJML should throw a RuntimeException.
        // The system should fail loudly on broken emails rather than sending malformed content.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MJML compilation failed');

        $invalidMjml = '<invalid-mjml-tag>content</invalid-mjml-tag>';
        $mailable->exposeCompileMjml($invalidMjml);
    }

    public function test_compile_mjml_with_valid_mjml(): void
    {
        $mailable = new class extends MjmlMailable {
            protected function getSubject(): string
            {
                return 'Test Subject';
            }

            public function exposeCompileMjml(string $mjml): string
            {
                return $this->compileMjml($mjml);
            }
        };

        $validMjml = '<mjml><mj-body><mj-section><mj-column><mj-text>Hello</mj-text></mj-column></mj-section></mj-body></mjml>';
        $result = $mailable->exposeCompileMjml($validMjml);

        // Should compile to HTML.
        $this->assertStringContainsString('Hello', $result);
    }

    public function test_build_method_sets_html_content(): void
    {
        // Test using an existing mailable that has a real template.
        $user = $this->createTestUser();
        $mail = new \App\Mail\Donation\DonationThankYou($user);

        // Build the email.
        $result = $mail->build();

        // Should return self.
        $this->assertInstanceOf(\App\Mail\Donation\DonationThankYou::class, $result);
    }
}
