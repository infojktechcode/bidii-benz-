<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Date-range parsing and validation for the staff/owner reports screen.
 *
 * Contract:
 *  - an empty value means "unbounded on that side"; two empty values = all time;
 *  - a value must be exactly YYYY-MM-DD and a real calendar date (no silent
 *    truncation, no rollover such as 2026-02-31);
 *  - start must not be after end.
 */
final class ReportRange
{
    /**
     * @param string|null $rawFrom submitted 'from' (raw)
     * @param string|null $rawTo submitted 'to' (raw)
     * @return array{from: ?string, to: ?string, errors: array<string, string>}
     */
    public static function parse(?string $rawFrom, ?string $rawTo): array
    {
        $errors = [];
        $from = self::one($rawFrom, 'from', $errors);
        $to = self::one($rawTo, 'to', $errors);

        if ($errors === [] && $from !== null && $to !== null && $from > $to) {
            $errors['range'] = 'The start date must be on or before the end date.';
        }

        return ['from' => $from, 'to' => $to, 'errors' => $errors];
    }

    /**
     * @param array<string, string> $errors
     */
    private static function one(?string $raw, string $field, array &$errors): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $errors[$field] = ucfirst($field) . ' must be a valid date (YYYY-MM-DD).';
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($dt === false || $dt->format('Y-m-d') !== $raw) {
            $errors[$field] = ucfirst($field) . ' must be a valid date (YYYY-MM-DD).';
            return null;
        }
        return $raw;
    }
}
