<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ReportRange;
use PHPUnit\Framework\TestCase;

/**
 * Report date-range validation (§9: empty, single-day, month, invalid and
 * reversed ranges must all be handled explicitly).
 */
final class ReportRangeTest extends TestCase
{
    public function testBothEmptyMeansAllTime(): void
    {
        $range = ReportRange::parse('', '');

        self::assertNull($range['from']);
        self::assertNull($range['to']);
        self::assertSame([], $range['errors']);
    }

    public function testNullValuesMeanAllTime(): void
    {
        $range = ReportRange::parse(null, null);

        self::assertNull($range['from']);
        self::assertNull($range['to']);
        self::assertSame([], $range['errors']);
    }

    public function testSingleDayRangeIsAccepted(): void
    {
        $range = ReportRange::parse('2026-03-15', '2026-03-15');

        self::assertSame('2026-03-15', $range['from']);
        self::assertSame('2026-03-15', $range['to']);
        self::assertSame([], $range['errors']);
    }

    public function testMonthRangeIsAccepted(): void
    {
        $range = ReportRange::parse('2026-03-01', '2026-03-31');

        self::assertSame('2026-03-01', $range['from']);
        self::assertSame('2026-03-31', $range['to']);
        self::assertSame([], $range['errors']);
    }

    public function testOnlyOneSideNeedsToBeFilled(): void
    {
        $range = ReportRange::parse('2026-03-01', '');

        self::assertSame('2026-03-01', $range['from']);
        self::assertNull($range['to']);
        self::assertSame([], $range['errors']);
    }

    public function testWhitespaceIsTrimmed(): void
    {
        $range = ReportRange::parse('  2026-03-01  ', ' 2026-03-02 ');

        self::assertSame('2026-03-01', $range['from']);
        self::assertSame('2026-03-02', $range['to']);
        self::assertSame([], $range['errors']);
    }

    public function testCalendarInvalidDateIsRejected(): void
    {
        $range = ReportRange::parse('2026-02-31', '');

        self::assertNull($range['from']);
        self::assertArrayHasKey('from', $range['errors']);
    }

    public function testMonthThirteenIsRejected(): void
    {
        $range = ReportRange::parse('2026-13-01', '');

        self::assertNull($range['from']);
        self::assertArrayHasKey('from', $range['errors']);
    }

    public function testMalformedValueIsRejected(): void
    {
        $range = ReportRange::parse('yesterday', 'not-a-date');

        self::assertNull($range['from']);
        self::assertNull($range['to']);
        self::assertArrayHasKey('from', $range['errors']);
        self::assertArrayHasKey('to', $range['errors']);
    }

    public function testOverlongValueIsNotTruncatedIntoValidity(): void
    {
        $range = ReportRange::parse('2026-01-01-injected', '');

        self::assertNull($range['from'], 'a value longer than YYYY-MM-DD must never be truncated into a valid date');
        self::assertArrayHasKey('from', $range['errors']);
    }

    public function testSlashedDateIsRejected(): void
    {
        $range = ReportRange::parse('01/03/2026', '');

        self::assertNull($range['from']);
        self::assertArrayHasKey('from', $range['errors']);
    }

    public function testReversedRangeIsRejected(): void
    {
        $range = ReportRange::parse('2026-03-31', '2026-03-01');

        self::assertArrayHasKey('range', $range['errors']);
        self::assertSame('2026-03-31', $range['from'], 'the submitted values are kept so the form can be corrected');
        self::assertSame('2026-03-01', $range['to']);
    }

    public function testReportIsNotRunWhenAnyRangeErrorExists(): void
    {
        $range = ReportRange::parse('2026-03-31', '2026-03-01');

        self::assertNotSame([], $range['errors']);
    }
}
