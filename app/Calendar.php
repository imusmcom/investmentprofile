<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/**
 * Calendar-month subtraction shared by Performance and Strategy. Anchor day overflow is clamped to
 * the target month's last day. Subtracting from an already-clamped date keeps 31 March minus three
 * months at 31 December instead of losing the original day to a large strtotime() jump.
 */
final class Calendar
{
    public static function subtractMonths(string $date, int $months): string
    {
        if ($months < 0) {
            throw new InvalidArgumentException('減月數不可為負。');
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        $totalMonths = $year * 12 + $month - 1 - $months;
        $year = intdiv($totalMonths, 12);
        $month = $totalMonths % 12 + 1;
        $last = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        $day = min($day, $last);

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
