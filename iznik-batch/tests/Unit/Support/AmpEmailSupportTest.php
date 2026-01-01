<?php

namespace Tests\Unit\Support;

use App\Support\AmpEmailSupport;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AMP email domain support checking.
 */
class AmpEmailSupportTest extends TestCase
{
    /**
     * @dataProvider gmailAddressesProvider
     */
    public function test_gmail_addresses_are_supported(string $email): void
    {
        $this->assertTrue(
            AmpEmailSupport::isSupported($email),
            "Expected Gmail address '$email' to be supported"
        );
    }

    public static function gmailAddressesProvider(): array
    {
        return [
            ['user@gmail.com'],
            ['USER@GMAIL.COM'],
            ['user@googlemail.com'],
            ['test.user+tag@gmail.com'],
            ['  user@gmail.com  '],
        ];
    }

    /**
     * @dataProvider yahooAddressesProvider
     */
    public function test_yahoo_addresses_are_supported(string $email): void
    {
        $this->assertTrue(
            AmpEmailSupport::isSupported($email),
            "Expected Yahoo address '$email' to be supported"
        );
    }

    public static function yahooAddressesProvider(): array
    {
        return [
            ['user@yahoo.com'],
            ['user@yahoo.co.uk'],
            ['user@yahoo.de'],
            ['user@yahoo.fr'],
            ['user@aol.com'],
            ['user@aol.co.uk'],
        ];
    }

    /**
     * @dataProvider mailRuAddressesProvider
     */
    public function test_mail_ru_addresses_are_supported(string $email): void
    {
        $this->assertTrue(
            AmpEmailSupport::isSupported($email),
            "Expected Mail.ru address '$email' to be supported"
        );
    }

    public static function mailRuAddressesProvider(): array
    {
        return [
            ['user@mail.ru'],
            ['user@inbox.ru'],
            ['user@list.ru'],
            ['user@bk.ru'],
        ];
    }

    /**
     * @dataProvider unsupportedAddressesProvider
     */
    public function test_unsupported_addresses_return_false(string $email): void
    {
        $this->assertFalse(
            AmpEmailSupport::isSupported($email),
            "Expected address '$email' to be unsupported"
        );
    }

    public static function unsupportedAddressesProvider(): array
    {
        return [
            // Microsoft dropped AMP support
            ['user@outlook.com'],
            ['user@hotmail.com'],
            ['user@live.com'],

            // Other unsupported providers
            ['user@icloud.com'],
            ['user@protonmail.com'],
            ['user@example.com'],
            ['user@ilovefreegle.org'],
            ['user@company.co.uk'],

            // ISP emails
            ['user@btinternet.com'],
            ['user@virginmedia.com'],
            ['user@sky.com'],
        ];
    }

    /**
     * @dataProvider invalidEmailsProvider
     */
    public function test_invalid_emails_return_false(string $email): void
    {
        $this->assertFalse(
            AmpEmailSupport::isSupported($email),
            "Expected invalid email '$email' to return false"
        );
    }

    public static function invalidEmailsProvider(): array
    {
        return [
            [''],
            ['   '],
            ['notanemail'],
            ['@nodomain.com'],
            ['user@'],
            ['user@@double.com'],
        ];
    }

    public function test_extract_domain_returns_correct_domain(): void
    {
        $this->assertEquals('gmail.com', AmpEmailSupport::extractDomain('user@gmail.com'));
        $this->assertEquals('yahoo.co.uk', AmpEmailSupport::extractDomain('test@yahoo.co.uk'));
        $this->assertEquals('example.com', AmpEmailSupport::extractDomain('User@EXAMPLE.COM'));
    }

    public function test_extract_domain_handles_whitespace(): void
    {
        $this->assertEquals('gmail.com', AmpEmailSupport::extractDomain('  user@gmail.com  '));
    }

    public function test_extract_domain_returns_null_for_invalid(): void
    {
        $this->assertNull(AmpEmailSupport::extractDomain(''));
        $this->assertNull(AmpEmailSupport::extractDomain('notanemail'));
        $this->assertNull(AmpEmailSupport::extractDomain('user@'));
    }

    public function test_is_domain_supported_works_directly(): void
    {
        $this->assertTrue(AmpEmailSupport::isDomainSupported('gmail.com'));
        $this->assertTrue(AmpEmailSupport::isDomainSupported('GMAIL.COM'));
        $this->assertFalse(AmpEmailSupport::isDomainSupported('outlook.com'));
        $this->assertFalse(AmpEmailSupport::isDomainSupported('example.com'));
    }

    public function test_get_supported_domains_returns_array(): void
    {
        $domains = AmpEmailSupport::getSupportedDomains();

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('gmail.com', $domains);
        $this->assertContains('yahoo.com', $domains);
    }

    public function test_filter_supported_returns_only_supported_emails(): void
    {
        $emails = [
            'user1@gmail.com',
            'user2@outlook.com',
            'user3@yahoo.com',
            'user4@example.com',
        ];

        $filtered = AmpEmailSupport::filterSupported($emails);

        $this->assertCount(2, $filtered);
        $this->assertContains('user1@gmail.com', $filtered);
        $this->assertContains('user3@yahoo.com', $filtered);
        $this->assertNotContains('user2@outlook.com', $filtered);
        $this->assertNotContains('user4@example.com', $filtered);
    }

    public function test_filter_supported_handles_empty_array(): void
    {
        $this->assertEquals([], AmpEmailSupport::filterSupported([]));
    }

    public function test_get_stats_returns_correct_counts(): void
    {
        $emails = [
            'user1@gmail.com',
            'user2@outlook.com',
            'user3@yahoo.com',
            'user4@example.com',
            'user5@mail.ru',
        ];

        $stats = AmpEmailSupport::getStats($emails);

        $this->assertEquals(3, $stats['supported']);
        $this->assertEquals(2, $stats['unsupported']);
        $this->assertEquals(5, $stats['total']);
        $this->assertEquals(60.0, $stats['percentage']);
    }

    public function test_get_stats_handles_empty_array(): void
    {
        $stats = AmpEmailSupport::getStats([]);

        $this->assertEquals(0, $stats['supported']);
        $this->assertEquals(0, $stats['unsupported']);
        $this->assertEquals(0, $stats['total']);
        $this->assertEquals(0.0, $stats['percentage']);
    }

    public function test_get_stats_handles_all_supported(): void
    {
        $emails = ['user@gmail.com', 'user@yahoo.com'];
        $stats = AmpEmailSupport::getStats($emails);

        $this->assertEquals(100.0, $stats['percentage']);
    }

    public function test_get_stats_handles_none_supported(): void
    {
        $emails = ['user@outlook.com', 'user@example.com'];
        $stats = AmpEmailSupport::getStats($emails);

        $this->assertEquals(0.0, $stats['percentage']);
    }

    public function test_yandex_addresses_are_supported(): void
    {
        $this->assertTrue(AmpEmailSupport::isSupported('user@yandex.ru'));
        $this->assertTrue(AmpEmailSupport::isSupported('user@yandex.com'));
        $this->assertTrue(AmpEmailSupport::isSupported('user@ya.ru'));
    }
}
