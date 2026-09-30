<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Login brute-force throttling. Pure logic — unit-testable without a database.
 *
 * Rules:
 *  - max 5 failed attempts per identifier (email/phone) in the window
 *  - max 20 failed attempts per IP address in the window
 */
final class RateLimiter
{
    public const WINDOW_SECONDS = 900;
    public const MAX_PER_IDENTIFIER = 5;
    public const MAX_PER_IP = 20;
    /** M-Pesa STK initiations allowed per client session inside the window. */
    public const MAX_INITIATES_PER_WINDOW = 10;
    /** Password-change attempts allowed per session inside the window. */
    public const MAX_PASSWORD_CHANGES_PER_WINDOW = 5;

    public static function isBlocked(int $identifierFailures, int $ipFailures): bool
    {
        return $identifierFailures >= self::MAX_PER_IDENTIFIER
            || $ipFailures >= self::MAX_PER_IP;
    }

    /**
     * True when $times already holds $max or more entries inside the window.
     * Shared by the login block and the payment-initiation throttle.
     *
     * @param list<int> $times
     */
    public static function overLimit(array $times, int $max, int $now): bool
    {
        return count(self::inWindow($times, $now)) >= $max;
    }

    /**
     * Seconds until the caller may retry, given failure timestamps (unix time,
     * ascending order) that triggered the block.
     *
     * @param list<int> $failureTimesAsc
     */
    public static function retryAfterSeconds(array $failureTimesAsc, int $max, int $now): int
    {
        $count = count($failureTimesAsc);
        if ($max < 1 || $count < $max) {
            return 0;
        }
        // The block lifts when enough of the OLDEST failures age out of the window.
        $oldestRelevant = $failureTimesAsc[$count - $max];
        $retryAt = $oldestRelevant + self::WINDOW_SECONDS;
        return max(0, $retryAt - $now);
    }

    /**
     * Keep only failures inside the window.
     *
     * @param list<int> $times
     * @return list<int>
     */
    public static function inWindow(array $times, int $now): array
    {
        $cutoff = $now - self::WINDOW_SECONDS;
        $kept = array_values(array_filter($times, static fn (int $t): bool => $t > $cutoff));
        sort($kept);
        return $kept;
    }
}
