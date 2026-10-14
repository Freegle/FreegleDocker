<?php

namespace App\Services\Ux;

/**
 * The arithmetic behind an experiment decision: two arms, each a count of successes and failures,
 * compared as Beta(1 + s, 1 + f) posteriors on their rates.
 *
 * probabilityBGreaterThanA is exact (the closed form in Miller 2015, "Formulas for Bayesian A/B
 * Testing", a sum over the smaller arm's successes of ratios of Beta functions), so a test can pin
 * it to the digit. The credible interval on the difference has no closed form and is sampled
 * with a fixed seed, so it is deterministic too.
 *
 * Why Bayesian and not a p-value: a 24-hour experiment is read every hour, and the question the
 * loop asks is "how sure are we the change is better", which a posterior probability answers
 * directly and a p-value does not. Nothing here is a significance test.
 */
final class BetaBinomial
{
    /**
     * P(rate_B > rate_A) given successes and failures in each arm, with uniform priors.
     */
    public static function probabilityBGreaterThanA(int $sa, int $fa, int $sb, int $fb): float
    {
        $aA = $sa + 1;
        $bA = $fa + 1;
        $aB = $sb + 1;
        $bB = $fb + 1;

        // Sum over the arm with fewer successes; the two are related by P(B>A) = 1 - P(A>B).
        if ($aB > $aA) {
            return 1.0 - self::probabilityBGreaterThanA($sb, $fb, $sa, $fa);
        }

        $total = 0.0;
        $lnBetaA = self::lnBeta($aA, $bA);
        for ($i = 0; $i < $aB; $i++) {
            $total += exp(
                self::lnBeta($aA + $i, $bA + $bB)
                - log($bB + $i)
                - self::lnBeta(1 + $i, $bB)
                - $lnBetaA
            );
        }

        return max(0.0, min(1.0, $total));
    }

    /**
     * The central credible interval of (rate_B - rate_A) at the given mass, in rate units
     * (0.03 is three points), by sampling the two posteriors with a fixed seed.
     *
     * @return array{0: float, 1: float}
     */
    public static function credibleIntervalDiff(int $sa, int $fa, int $sb, int $fb, float $mass = 0.9, int $samples = 20000): array
    {
        mt_srand(20261014);
        $diffs = [];
        for ($i = 0; $i < $samples; $i++) {
            $diffs[] = self::sampleBeta($sb + 1, $fb + 1) - self::sampleBeta($sa + 1, $fa + 1);
        }
        sort($diffs);
        $lo = (int) floor((1 - $mass) / 2 * $samples);
        $hi = (int) ceil((1 + $mass) / 2 * $samples) - 1;

        return [$diffs[max(0, $lo)], $diffs[min($samples - 1, $hi)]];
    }

    /** The posterior mean of a rate. */
    public static function mean(int $s, int $f): float
    {
        return ($s + 1) / ($s + $f + 2);
    }

    public static function lnBeta(float $a, float $b): float
    {
        return self::lnGamma($a) + self::lnGamma($b) - self::lnGamma($a + $b);
    }

    /** Lanczos approximation, accurate to about 1e-13 for positive arguments. */
    public static function lnGamma(float $x): float
    {
        static $g = 7;
        static $c = [
            0.99999999999980993, 676.5203681218851, -1259.1392167224028, 771.32342877765313,
            -176.61502916214059, 12.507343278686905, -0.13857109526572012, 9.9843695780195716e-6,
            1.5056327351493116e-7,
        ];

        if ($x < 0.5) {
            return log(M_PI / sin(M_PI * $x)) - self::lnGamma(1 - $x);
        }

        $x -= 1;
        $a = $c[0];
        $t = $x + $g + 0.5;
        for ($i = 1; $i < $g + 2; $i++) {
            $a += $c[$i] / ($x + $i);
        }

        return 0.5 * log(2 * M_PI) + ($x + 0.5) * log($t) - $t + log($a);
    }

    /** A Beta(a, b) sample as the normalised ratio of two Gamma samples. */
    private static function sampleBeta(float $a, float $b): float
    {
        $x = self::sampleGamma($a);
        $y = self::sampleGamma($b);

        return $x / ($x + $y);
    }

    /** Marsaglia and Tsang (2000), for shape >= 1, which 1 + a count always is. */
    private static function sampleGamma(float $shape): float
    {
        $d = $shape - 1 / 3;
        $c = 1 / sqrt(9 * $d);
        while (true) {
            do {
                $x = self::sampleNormal();
                $v = 1 + $c * $x;
            } while ($v <= 0);
            $v = $v * $v * $v;
            $u = self::uniform();
            if ($u < 1 - 0.0331 * $x * $x * $x * $x) {
                return $d * $v;
            }
            if (log($u) < 0.5 * $x * $x + $d * (1 - $v + log($v))) {
                return $d * $v;
            }
        }
    }

    private static function sampleNormal(): float
    {
        $u1 = max(self::uniform(), 1e-12);
        $u2 = self::uniform();

        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    private static function uniform(): float
    {
        return (mt_rand() + 0.5) / (mt_getrandmax() + 1);
    }
}
