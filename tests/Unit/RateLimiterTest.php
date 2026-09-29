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
}
