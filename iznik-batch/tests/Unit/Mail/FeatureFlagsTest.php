<?php

namespace Tests\Unit\Mail;

use Tests\TestCase;

class FeatureFlagsTest extends TestCase
{
    use \App\Mail\Traits\FeatureFlags;

    public function test_email_type_enabled_when_in_config(): void
    {
        // The phpunit.xml sets FREEGLE_MAIL_ENABLED_TYPES=Welcome,ChatNotification
        $this->assertTrue(self::isEmailTypeEnabled('Welcome'));
        $this->assertTrue(self::isEmailTypeEnabled('ChatNotification'));
    }

    public function test_email_type_disabled_when_not_in_config(): void
    {
        // Types not in the config should be disabled.
        $this->assertFalse(self::isEmailTypeEnabled('SomeOtherType'));
        $this->assertFalse(self::isEmailTypeEnabled('Digest'));
    }

    public function test_email_type_disabled_when_config_empty(): void
    {
        // Temporarily set config to empty.
        config(['freegle.mail.enabled_types' => '']);

        $this->assertFalse(self::isEmailTypeEnabled('Welcome'));
        $this->assertFalse(self::isEmailTypeEnabled('ChatNotification'));

        // Restore for other tests.
        config(['freegle.mail.enabled_types' => 'Welcome,ChatNotification']);
    }

    public function test_email_type_handles_whitespace(): void
    {
        // Test that whitespace is trimmed.
        config(['freegle.mail.enabled_types' => 'Welcome , ChatNotification']);

        $this->assertTrue(self::isEmailTypeEnabled('Welcome'));
        $this->assertTrue(self::isEmailTypeEnabled('ChatNotification'));

        // Restore.
        config(['freegle.mail.enabled_types' => 'Welcome,ChatNotification']);
    }
}
