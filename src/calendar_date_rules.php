<?php
/**
 * calendar_date_rules.php — resolves "movable" calendar_entries rows (dates
 * that shift every year -- Dia das Mães, Dia dos Pais, Carnaval, Sexta-Feira
 * Santa, Corpus Christi, etc) to a concrete month/day for a GIVEN year.
 *
 * calendar_entries.month/day used to be the only source of truth for a
 * row's date, which only works for genuinely fixed dates (Natal, 25/12
 * every year). A movable commemoration/holiday was stored as whatever
 * month/day it happened to fall on the year it was imported/typed in --
 * correct for that one year, silently wrong for every other year, with no
 * way to tell the two apart. calendar_entries.date_rule (nullable JSON
 * TEXT) is the fix: when present, it's the actual source of truth for the
 * row's date, resolved fresh for whatever year is being queried via
 * calendarDateRuleResolve() below -- month/day stay populated too (see
 * repo.php's calendarEntryRowFromInput()) only as a fallback reference
 * value (admin list sorting, CSV export, anything that hasn't been taught
 * about rules), always recomputed for the CURRENT year on every save.
 *
 * Supported rule shapes (all JSON objects with a "type" key):
 *   {"type":"nth_weekday","month":5,"weekday":0,"nth":2}
 *     -- Nth occurrence of `weekday` (0=domingo..6=sábado, same as PHP's
 *     date('w')) in `month`. E.g. Dia das Mães (BR) = 2nd Sunday of May.
 *     Clamped to the LAST occurrence if the month doesn't have an Nth one
 *     (e.g. a "5th Sunday" that doesn't exist that year) instead of
 *     overflowing into the next month.
 *   {"type":"last_weekday","month":11,"weekday":4}
 *     -- Last occurrence of `weekday` in `month`.
 *   {"type":"easter_offset","offset":-47}
 *     -- `offset` days from Easter Sunday (negative = before, positive =
 *     after, 0 = Easter itself). E.g. Carnaval (BR) = Easter - 47 days,
 *     Sexta-Feira Santa = Easter - 2 days, Corpus Christi = Easter + 60.
 */

const CALENDAR_DATE_RULE_TYPES = ['nth_weekday', 'last_weekday', 'easter_offset'];

/**
 * Easter Sunday (Gregorian) for a given year -- the anonymous Gregorian
 * algorithm (Meeus/Jones/Butcher). Implemented directly (instead of relying
 * on PHP's easter_date(), which needs the optional `calendar` extension and
 * only supports years up to 2037 on 32-bit builds) so this has no external
 * dependency and no year-range surprise.
 */
function calendarEasterSunday(int $year): DateTimeImmutable {
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

/** Nth (1-5) occurrence of `weekday` in `month`/`year`, clamped to the last one if it would overflow. */
function calendarDateRuleNthWeekday(int $year, int $month, int $weekday, int $nth): array {
    $month   = max(1, min(12, $month));
    $weekday = (($weekday % 7) + 7) % 7;
    $nth     = max(1, min(5, $nth));

    $first        = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $firstWeekday = (int) $first->format('w');
    $offset       = ($weekday - $firstWeekday + 7) % 7;
    $day          = 1 + $offset + ($nth - 1) * 7;

    $daysInMonth = (int) $first->format('t');
    if ($day > $daysInMonth) {
        $day -= 7; // that "Nth" occurrence doesn't exist this year -- fall back to the previous (last valid) one
    }
    return ['month' => $month, 'day' => $day];
}

/** Last occurrence of `weekday` in `month`/`year`. */
function calendarDateRuleLastWeekday(int $year, int $month, int $weekday): array {
    $month   = max(1, min(12, $month));
    $weekday = (($weekday % 7) + 7) % 7;

    $first       = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $daysInMonth = (int) $first->format('t');
    $last        = $first->setDate($year, $month, $daysInMonth);
    $lastWeekday = (int) $last->format('w');
    $diff        = ($lastWeekday - $weekday + 7) % 7;

    return ['month' => $month, 'day' => $daysInMonth - $diff];
}

/** `offsetDays` days from Easter Sunday of `year` (negative = before, positive = after). */
function calendarDateRuleEasterOffset(int $year, int $offsetDays): array {
    $target = calendarEasterSunday($year)->modify($offsetDays . ' days');
    return ['month' => (int) $target->format('n'), 'day' => (int) $target->format('j')];
}

/**
 * Resolves any supported rule to a {month, day} pair for `$year`. Throws on
 * an unknown/malformed rule -- callers that need a soft-fail fallback
 * instead (e.g. repo.php's calendarEntryResolvedMonthDay(), reading a row
 * that might have a corrupted/legacy date_rule value) should catch this.
 */
function calendarDateRuleResolve(array $rule, int $year): array {
    switch ($rule['type'] ?? '') {
        case 'nth_weekday':
            return calendarDateRuleNthWeekday($year, (int) ($rule['month'] ?? 1), (int) ($rule['weekday'] ?? 0), (int) ($rule['nth'] ?? 1));
        case 'last_weekday':
            return calendarDateRuleLastWeekday($year, (int) ($rule['month'] ?? 1), (int) ($rule['weekday'] ?? 0));
        case 'easter_offset':
            return calendarDateRuleEasterOffset($year, (int) ($rule['offset'] ?? 0));
        default:
            throw new InvalidArgumentException('Tipo de regra de data desconhecido: ' . (string) ($rule['type'] ?? ''));
    }
}

/**
 * Human-readable (pt-BR) summary of a rule -- used by the admin list/form
 * so a movable entry reads as e.g. "2º domingo de maio" instead of a bare
 * JSON blob or a misleading fixed date.
 */
function calendarDateRuleDescribe(array $rule): string {
    $months   = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $weekdays = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    $nths     = ['', '1º', '2º', '3º', '4º', '5º'];

    switch ($rule['type'] ?? '') {
        case 'nth_weekday':
            $n  = max(1, min(5, (int) ($rule['nth'] ?? 1)));
            $wd = (((int) ($rule['weekday'] ?? 0)) % 7 + 7) % 7;
            $mo = max(1, min(12, (int) ($rule['month'] ?? 1)));
            return $nths[$n] . ' ' . $weekdays[$wd] . ' de ' . $months[$mo];
        case 'last_weekday':
            $wd = (((int) ($rule['weekday'] ?? 0)) % 7 + 7) % 7;
            $mo = max(1, min(12, (int) ($rule['month'] ?? 1)));
            return 'último ' . $weekdays[$wd] . ' de ' . $months[$mo];
        case 'easter_offset':
            $off = (int) ($rule['offset'] ?? 0);
            if ($off === 0) {
                return 'domingo de Páscoa';
            }
            return $off > 0 ? $off . ' dia(s) após a Páscoa' : abs($off) . ' dia(s) antes da Páscoa';
        default:
            return 'regra desconhecida';
    }
}

/**
 * Builds a rule array (ready for json_encode()) from the admin form's POST
 * fields -- mirrors calendarEntryRowFromInput()'s own "normalize raw input"
 * role, kept here instead since it's specific to whichever rule type is
 * selected. Returns null if `date_type` isn't 'rule' or the chosen rule
 * type is invalid/incomplete.
 */
function calendarDateRuleFromInput(array $d): ?array {
    if (($d['date_type'] ?? 'fixed') !== 'rule') {
        return null;
    }
    $type = (string) ($d['rule_type'] ?? '');
    if (!in_array($type, CALENDAR_DATE_RULE_TYPES, true)) {
        return null;
    }

    switch ($type) {
        case 'nth_weekday':
            return [
                'type'    => 'nth_weekday',
                'month'   => max(1, min(12, (int) ($d['rule_month'] ?? 1))),
                'weekday' => max(0, min(6, (int) ($d['rule_weekday'] ?? 0))),
                'nth'     => max(1, min(5, (int) ($d['rule_nth'] ?? 1))),
            ];
        case 'last_weekday':
            return [
                'type'    => 'last_weekday',
                'month'   => max(1, min(12, (int) ($d['rule_month'] ?? 1))),
                'weekday' => max(0, min(6, (int) ($d['rule_weekday'] ?? 0))),
            ];
        case 'easter_offset':
            return [
                'type'   => 'easter_offset',
                'offset' => (int) ($d['rule_offset'] ?? 0),
            ];
        default:
            return null;
    }
}
