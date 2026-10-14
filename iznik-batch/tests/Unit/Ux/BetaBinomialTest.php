<?php

namespace Tests\Unit\Ux;

use App\Services\Ux\BetaBinomial;
use PHPUnit\Framework\TestCase;

class BetaBinomialTest extends TestCase
{
    public function test_identical_arms_are_a_coin_toss(): void
    {
        $this->assertEqualsWithDelta(0.5, BetaBinomial::probabilityBGreaterThanA(50, 50, 50, 50), 1e-9);
        $this->assertEqualsWithDelta(0.5, BetaBinomial::probabilityBGreaterThanA(0, 0, 0, 0), 1e-9);
    }

    public function test_known_values_from_the_closed_form(): void
    {
        // Checked against an independent evaluation of the same closed form: A 10 of 90, B 15 of 85.
        $this->assertEqualsWithDelta(0.8885, BetaBinomial::probabilityBGreaterThanA(10, 80, 15, 70), 0.001);
        // The complement: swapping the arms gives one minus the result.
        $p = BetaBinomial::probabilityBGreaterThanA(200, 220, 230, 190);
        $this->assertEqualsWithDelta(1 - $p, BetaBinomial::probabilityBGreaterThanA(230, 190, 200, 220), 1e-9);
    }

    public function test_a_clear_winner_is_near_certain_and_a_clear_loser_near_zero(): void
    {
        $this->assertGreaterThan(0.999, BetaBinomial::probabilityBGreaterThanA(100, 300, 200, 200));
        $this->assertLessThan(0.001, BetaBinomial::probabilityBGreaterThanA(200, 200, 100, 300));
    }

    public function test_small_samples_stay_uncertain(): void
    {
        // 6 of 10 against 4 of 10 is nowhere near a decision.
        $p = BetaBinomial::probabilityBGreaterThanA(4, 6, 6, 4);
        $this->assertGreaterThan(0.6, $p);
        $this->assertLessThan(0.9, $p);
    }

    public function test_result_is_always_a_probability(): void
    {
        foreach ([[0, 0, 0, 1], [1, 0, 0, 0], [1000, 1, 1, 1000], [3, 0, 0, 3]] as [$sa, $fa, $sb, $fb]) {
            $p = BetaBinomial::probabilityBGreaterThanA($sa, $fa, $sb, $fb);
            $this->assertGreaterThanOrEqual(0.0, $p);
            $this->assertLessThanOrEqual(1.0, $p);
        }
    }

    public function test_credible_interval_is_deterministic_and_brackets_the_true_difference(): void
    {
        [$lo, $hi] = BetaBinomial::credibleIntervalDiff(200, 200, 240, 160);
        [$lo2, $hi2] = BetaBinomial::credibleIntervalDiff(200, 200, 240, 160);
        $this->assertSame($lo, $lo2);
        $this->assertSame($hi, $hi2);
        // The true difference is 0.10; a 90% interval on 400 per arm is roughly 0.10 +/- 0.06.
        $this->assertLessThan(0.10, $lo);
        $this->assertGreaterThan(0.10, $hi);
        $this->assertGreaterThan(0.0, $lo);
        $this->assertLessThan(0.2, $hi);
    }

    public function test_ln_gamma_matches_factorials(): void
    {
        $this->assertEqualsWithDelta(log(120), BetaBinomial::lnGamma(6), 1e-9);
        $this->assertEqualsWithDelta(log(3628800), BetaBinomial::lnGamma(11), 1e-9);
        $this->assertEqualsWithDelta(0.5 * log(M_PI), BetaBinomial::lnGamma(0.5), 1e-9);
    }

    public function test_posterior_mean_shrinks_towards_a_half(): void
    {
        $this->assertSame(0.5, BetaBinomial::mean(0, 0));
        $this->assertEqualsWithDelta(0.6, BetaBinomial::mean(599, 399), 1e-9);
    }
}
