<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testNotBlockedBelowIdentifierThreshold(): void
    {
        self::assertFalse(RateLimiter::isBlocked(0, 0));
        self::assertFalse(RateLimiter::isBlocked(4, 19));
    }

    public function testBlockedAtIdentifierThreshold(): void
    {
        self::assertTrue(RateLimiter::isBlocked(5, 0));
        self::assertTrue(RateLimiter::isBlocked(6, 0));
    }

    public function testBlockedAtIpThresholdEvenWithFreshIdentifier(): void
    {
        self::assertTrue(RateLimiter::isBlocked(0, 20));
    }

    public function testRetryAfterReturnsZeroWhenNotEnoughFailures(): void
    {
        self::assertSame(0, RateLimiter::retryAfterSeconds([1000, 2000], 5, 3000));
    }

    public function testRetryAfterIsBasedOnOldestFailureThatStillCounts(): void
    {
        // 5 failures at t=1000..1400, now=1500, max=5.
        // Block lifts when the oldest one (1000) leaves the window: 1000+900=1900.
        $times = [1000, 1100, 1200, 1300, 1400];
        self::assertSame(400, RateLimiter::retryAfterSeconds($times, 5, 1500));
    }

    public function testRetryAfterIsNeverNegative(): void
    {
        $times = [1000, 1100, 1200, 1300, 1400];
        self::assertSame(0, RateLimiter::retryAfterSeconds($times, 5, 99999));
    }

    public function testInWindowDropsExpiredFailures(): void
    {
        $now = 10000;
        $times = [1000, 9500, 9999, 10000];
        $kept = RateLimiter::inWindow($times, $now);
        // Window is 900s: keep only t > 9100.
        self::assertSame([9500, 9999, 10000], $kept);
    }

    public function testInWindowSortsAscending(): void
    {
        $kept = RateLimiter::inWindow([9900, 9200, 9600], 10000);
        self::assertSame([9200, 9600, 9900], $kept);
    }

    public function testOverLimitIsFalseBelowThePaymentInitiationCap(): void
    {
        $now = 10000;
        $nine = array_fill(0, 9, $now - 100);
        self::assertFalse(
            RateLimiter::overLimit($nine, RateLimiter::MAX_INITIATES_PER_WINDOW, $now)
        );
    }

    public function testOverLimitFiresAtThePaymentInitiationCapAndIgnoresStaleEntries(): void
    {
        $now = 10000;
        $ten = array_fill(0, RateLimiter::MAX_INITIATES_PER_WINDOW, $now - 100);
        self::assertTrue(RateLimiter::overLimit($ten, RateLimiter::MAX_INITIATES_PER_WINDOW, $now));

        // 20 attempts, but 11 of them aged out of the 900s window: back under.
        $stale = array_fill(0, 11, $now - 901);
        $fresh = array_fill(0, 9, $now - 10);
        self::assertFalse(
            RateLimiter::overLimit(array_merge($stale, $fresh), RateLimiter::MAX_INITIATES_PER_WINDOW, $now),
            'stale attempts must not keep a client throttled after the window'
        );
    }

    public function testRegistrationThrottleFiresAtTheConfiguredLimit(): void
    {
        $now = 10000;
        $max = RateLimiter::MAX_REGISTRATIONS_PER_WINDOW;

        $under = array_fill(0, $max - 1, $now - 10);
        self::assertFalse(RateLimiter::overLimit($under, $max, $now));

        $atLimit = array_fill(0, $max, $now - 10);
        self::assertTrue(RateLimiter::overLimit($atLimit, $max, $now));

        // Attempts that aged out of the 900s window stop counting.
        $stale = array_fill(0, $max, $now - 901);
        self::assertFalse(RateLimiter::overLimit($stale, $max, $now));
    }
}
