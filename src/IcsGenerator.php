<?php

declare(strict_types=1);

namespace CisteniBkom;

use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\Entity\TimeZone;
use Eluceo\iCal\Domain\ValueObject\Alarm;
use Eluceo\iCal\Domain\ValueObject\Alarm\DisplayAction;
use Eluceo\iCal\Domain\ValueObject\Alarm\RelativeTrigger;
use Eluceo\iCal\Domain\ValueObject\DateTime;
use Eluceo\iCal\Domain\ValueObject\GeographicPosition;
use Eluceo\iCal\Domain\ValueObject\Location;
use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\UniqueIdentifier;
use Eluceo\iCal\Domain\ValueObject\Uri;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;

/**
 * Generuje iCalendar z uklidu.
 */
final class IcsGenerator
{
    public const TIMEZONE = 'Europe/Prague';

    private const SOURCE_URL = 'https://cisteni.bkom.cz/cs';
    private const CANCELLED_PREFIX = '[ZRUSENO] ';

    /** Kolik hodin pred zacatkem se ma pripomenout (18 h = vecer predem u rannich uklidu). */
    private const REMINDER_HOURS_BEFORE = 18;

    /**
     * @param array<int, Sweep> $sweeps
     */
    public function generate(array $sweeps, string $calendarName, ?Street $street = null): string
    {
        $calendar = new Calendar();

        foreach ($sweeps as $sweep) {
            $calendar->addEvent($this->createEvent($sweep, $street));
        }

        // VTIMEZONE musi byt v souboru, jinak si nektere klienty TZID nesparuji
        // s pravidly pro letni cas a udalost posunou o hodinu.
        $calendar->addTimeZone($this->timeZone($sweeps));

        $factory = new CalendarFactory();
        $ics = (string) $factory->createCalendar($calendar);

        $ics = $this->addCancelledStatus($ics);

        return $this->addCalendarHeaders($ics, $calendarName);
    }

    /**
     * VTIMEZONE pokryvajici rozsah udalosti v kalendari (s rezervou jeden rok).
     *
     * @param array<int, Sweep> $sweeps
     */
    private function timeZone(array $sweeps): TimeZone
    {
        $timestamps = array_map(static fn (Sweep $sweep): int => $sweep->from->getTimestamp(), $sweeps);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $begin = $timestamps === [] ? $now : (new \DateTimeImmutable('@' . min($timestamps)));
        $end = $timestamps === [] ? $now : (new \DateTimeImmutable('@' . max($timestamps)));

        return TimeZone::createFromPhpDateTimeZone(
            new \DateTimeZone(self::TIMEZONE),
            $begin->modify('-1 year'),
            $end->modify('+1 year'),
        );
    }

    private function createEvent(Sweep $sweep, ?Street $street): Event
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $start = $sweep->from->setTimezone($timezone);
        $end = $sweep->to->setTimezone($timezone);

        $event = new Event(new UniqueIdentifier($sweep->id . '@cisteni.bkom.cz'));

        $summary = 'Blokové čištění: ' . ($sweep->sectionName !== '' ? $sweep->sectionName : $sweep->name);
        if ($sweep->isCancelled()) {
            $summary = self::CANCELLED_PREFIX . $summary;
        }

        $event->setSummary($summary);
        $event->setDescription($this->createDescription($sweep, $street));
        $event->setOccurrence(new TimeSpan(new DateTime($start, true), new DateTime($end, true)));
        $event->setUrl(new Uri(self::SOURCE_URL));

        $streetName = $street?->name ?? $sweep->sectionName;
        $location = new Location($streetName . ', Brno');

        $lat = $sweep->lat ?? $street?->lat;
        $lon = $sweep->lon ?? $street?->lon;
        if ($lat !== null && $lon !== null) {
            $location = $location->withGeographicPosition(new GeographicPosition($lat, $lon));
        }
        $event->setLocation($location);

        if (!$sweep->isCancelled()) {
            $event->addAlarm(new Alarm(
                new DisplayAction('Blokové čištění: ' . $sweep->sectionName . ' - odstraňte vozidlo.'),
                (new RelativeTrigger($this->reminderOffset()))->withRelationToStart(),
            ));
        }

        return $event;
    }

    /**
     * Interval pripomenuti - zaporny, tj. pred zacatkem udalosti.
     */
    private function reminderOffset(): \DateInterval
    {
        $interval = new \DateInterval('PT' . self::REMINDER_HOURS_BEFORE . 'H');
        $interval->invert = 1;

        return $interval;
    }

    private function createDescription(Sweep $sweep, ?Street $street): string
    {
        $lines = [];

        if ($sweep->isCancelled()) {
            $lines[] = 'TERMÍN BYL ZRUŠEN NEBO PŘESUNUT.';
            $lines[] = '';
        } else {
            $lines[] = 'Odstraňte vozidlo z ulice, jinak hrozí odtah.';
            $lines[] = '';
        }

        if ($street !== null) {
            $lines[] = 'Ulice: ' . $street->name;
            if ($street->cityPart !== null) {
                $lines[] = 'Městská část: ' . $street->cityPart;
            }
        }

        $lines[] = 'Úsek: ' . $sweep->sectionName;
        $lines[] = '';
        $lines[] = 'Zdroj: ' . self::SOURCE_URL;
        $lines[] = 'Závazné je dopravní značení na místě.';

        return implode("\n", $lines);
    }

    /**
     * Doplni STATUS:CANCELLED k udalostem se zrusenym terminem.
     *
     * eluceo/ical vlastnost STATUS neumi, musi se dopsat do vysledneho textu.
     * Respektuje RFC 5545 skladani radku (pokracovaci radky zacinaji mezerou/tabem).
     */
    private function addCancelledStatus(string $ics): string
    {
        $lines = explode("\r\n", $ics);
        $result = [];
        $needsStatus = false;

        foreach ($lines as $line) {
            $isContinuation = $line !== '' && ($line[0] === ' ' || $line[0] === "\t");

            if ($needsStatus && !$isContinuation) {
                $result[] = 'STATUS:CANCELLED';
                $needsStatus = false;
            }

            $result[] = $line;

            if (str_starts_with($line, 'SUMMARY:') && str_contains($line, self::CANCELLED_PREFIX)) {
                $needsStatus = true;
            }
        }

        return implode("\r\n", $result);
    }

    /**
     * Doplni X-WR-* hlavicky, ktere eluceo/ical negeneruje, ale kalendarove
     * aplikace podle nich pojmenuji odebrany kalendar.
     */
    private function addCalendarHeaders(string $ics, string $calendarName): string
    {
        $headers = implode("\r\n", [
            'X-WR-CALNAME:' . $this->escapeText($calendarName),
            'X-WR-CALDESC:' . $this->escapeText('Termíny blokového čištění ulic v Brně (zdroj: BKOM)'),
            'X-WR-TIMEZONE:' . self::TIMEZONE,
            'NAME:' . $this->escapeText($calendarName),
            'REFRESH-INTERVAL;VALUE=DURATION:PT12H',
            'X-PUBLISHED-TTL:PT12H',
        ]);

        return preg_replace(
            '/^(PRODID:[^\r\n]*(?:\r\n[ \t][^\r\n]*)*)/m',
            '$1' . "\r\n" . $headers,
            $ics,
            1,
        ) ?? $ics;
    }

    private function escapeText(string $value): string
    {
        return str_replace(["\\", ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }
}
