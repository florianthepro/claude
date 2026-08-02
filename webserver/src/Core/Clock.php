<?php

declare(strict_types=1);

namespace Stimmwerk\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Zentrale Zeitquelle. Alle Zeitstempel werden als UTC-Strings
 * ('Y-m-d H:i:s') gespeichert; Tagesgrenzen (00:00) beziehen sich auf die
 * konfigurierte Zeitzone (Europe/Berlin). Für die Selbsttests lässt sich die
 * aktuelle Zeit deterministisch setzen.
 */
final class Clock
{
    public const FORMAT = 'Y-m-d H:i:s';

    private static ?DateTimeImmutable $testNow = null;
    private static string $tz = 'Europe/Berlin';

    public static function setTimezone(string $tz): void
    {
        self::$tz = $tz;
    }

    public static function setTestNow(?DateTimeImmutable $now): void
    {
        self::$testNow = $now?->setTimezone(new DateTimeZone('UTC'));
    }

    /** Jetzt, in UTC. */
    public static function now(): DateTimeImmutable
    {
        return self::$testNow ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function nowStr(): string
    {
        return self::now()->format(self::FORMAT);
    }

    /** Heutiges Datum (YYYY-MM-DD) in der lokalen Bezugszeitzone. */
    public static function localDate(): string
    {
        return self::now()->setTimezone(new DateTimeZone(self::$tz))->format('Y-m-d');
    }

    /** Nächste Mitternacht (00:00) der Bezugszeitzone, als UTC-String. */
    public static function nextLocalMidnightUtcStr(): string
    {
        $local = self::now()->setTimezone(new DateTimeZone(self::$tz));
        return $local->modify('tomorrow')->setTime(0, 0, 0)
            ->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function addHoursStr(string $utc, int $hours): string
    {
        return self::fromStr($utc)->modify(sprintf('%+d hours', $hours))->format(self::FORMAT);
    }

    public static function addDaysStr(string $utc, int $days): string
    {
        return self::fromStr($utc)->modify(sprintf('%+d days', $days))->format(self::FORMAT);
    }

    public static function fromStr(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    /** UTC-String formatiert für die Anzeige in der Bezugszeitzone. */
    public static function displayLocal(string $utc, string $format = 'd.m.Y H:i'): string
    {
        return self::fromStr($utc)->setTimezone(new DateTimeZone(self::$tz))->format($format);
    }
}
