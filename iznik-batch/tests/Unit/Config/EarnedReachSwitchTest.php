<?php

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RIPPLE_EARNED_REACH_ENABLED is read by both the batch (the gate on rippling) and the Go API
 * (the reviewer pool in microvolunteering.go), so both must say the same thing for the same
 * value. The rule is the one every post-moderation switch uses (App\Support\EnvSetting::flag,
 * pinned in EnvSettingTest): on for "true" or "1", in any case, and nothing else. This used to
 * be `(bool) env(...)`, which PHP reads "off", "no" and "yes" as ON, so a value the Go side
 * (rightly) took as off turned the batch's gate on.
 */
class EarnedReachSwitchTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function values(): array
    {
        return [
            'true' => ['true', true],
            'TRUE' => ['TRUE', true],
            'one' => ['1', true],
            'padded one' => [' 1 ', true],
            'padded true' => ['  true ', true],
            'yes is not on' => ['yes', false],
            'on is not on' => ['on', false],
            'off is off' => ['off', false],
            'no is off' => ['no', false],
            'disabled is off' => ['disabled', false],
            'false' => ['false', false],
            'zero' => ['0', false],
            'two' => ['2', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('values')]
    public function test_the_gate_reads_the_value_by_the_one_rule(string $value, bool $expected): void
    {
        $this->assertSame($expected, $this->resolvedGate($value));
    }

    public function test_the_gate_is_off_when_the_variable_is_not_set_at_all(): void
    {
        $this->assertFalse($this->resolvedGate(null));
    }

    /**
     * Re-evaluate config/freegle.php with the variable set to $value (or removed when null) and
     * return ripple.earned_reach_enabled, reading the file directly rather than the booted
     * app's config so this tests the resolution itself.
     */
    private function resolvedGate(?string $value): bool
    {
        $key = 'RIPPLE_EARNED_REACH_ENABLED';

        $saved = [
            'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
            'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
            'getenv' => getenv($key),
        ];

        try {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }

            $config = require base_path('config/freegle.php');

            return $config['ripple']['earned_reach_enabled'];
        } finally {
            if ($saved['getenv'] === false) {
                putenv($key);
            } else {
                putenv("{$key}={$saved['getenv']}");
            }
            if ($saved['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $saved['env'];
            }
            if ($saved['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $saved['server'];
            }
        }
    }
}
