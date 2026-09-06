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

    /** Maximalni delka radku dle RFC 5545 (bez ukonceni CRLF). */
    private const MAX_LINE_OCTETS = 75;

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

        $ics = $this->addEventProperties($ics, $sweeps);

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

        $sectionName = $this->sanitizeName($sweep->sectionName !== '' ? $sweep->sectionName : $sweep->name);
        $streetName = $this->sanitizeName($street?->name ?? $sectionName);

        $summary = 'Blokové čištění: ' . $sectionName;
        if ($sweep->isCancelled()) {
            $summary = self::CANCELLED_PREFIX . $summary;
        }

        $event->setSummary($summary);
        $event->setDescription($this->createDescription($sweep, $street, $sectionName, $streetName));
        $event->setOccurrence(new TimeSpan(new DateTime($start, true), new DateTime($end, true)));
        $event->setUrl(new Uri(self::SOURCE_URL));

        $location = new Location($streetName . ', Brno');

        $lat = $sweep->lat ?? $street?->lat;
        $lon = $sweep->lon ?? $street?->lon;
        if ($lat !== null && $lon !== null) {
            $location = $location->withGeographicPosition(new GeographicPosition($lat, $lon));
        }
        $event->setLocation($location);

        if (!$sweep->isCancelled()) {
            $event->addAlarm(new Alarm(
                new DisplayAction('Blokové čištění: ' . $sectionName . ' - odstraňte vozidlo.'),
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

    /**
     * Nazev prichazi z ciziho API a zobrazuje se uzivateli. Nesmi predstirat
     * nas vlastni prefix pro zruseny termin - jinak by slo v odebranem
     * kalendari vyvolat dojem, ze uklid neplati.
     */
    private function sanitizeName(string $name): string
    {
        return trim(str_replace([self::CANCELLED_PREFIX, '[ZRUSENO]'], '', $name));
    }

    private function createDescription(
        Sweep $sweep,
        ?Street $street,
        string $sectionName,
        string $streetName,
    ): string
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
            $lines[] = 'Ulice: ' . $streetName;
            if ($street->cityPart !== null) {
                $lines[] = 'Městská část: ' . $this->sanitizeName($street->cityPart);
            }
        }

        $lines[] = 'Úsek: ' . $sectionName;
        $lines[] = '';
        $lines[] = 'Zdroj: ' . self::SOURCE_URL;
        $lines[] = 'Závazné je dopravní značení na místě.';

        return implode("\n", $lines);
    }

    /**
     * Doplni vlastnosti, ktere eluceo/ical negeneruje: SEQUENCE u kazde udalosti
     * a STATUS:CANCELLED u zrusenych.
     *
     * SEQUENCE je podstatne prave u ruseni - klient, ktery uz udalost zna, muze
     * novou verzi se stejnou (implicitni) revizi povazovat za nezmenenou a zruseni
     * by se k uzivateli nedostalo.
     *
     * Zaznamy se paruji podle UID, ne podle textu shrnuti - nazev useku prichazi
     * z ciziho API a kdyby obsahoval nas vlastni prefix, oznacil by se jako
     * zruseny i termin, ktery ve skutecnosti plati.
     *
     * @param array<int, Sweep> $sweeps
     */
    private function addEventProperties(string $ics, array $sweeps): string
    {
        $byUid = [];
        foreach ($sweeps as $sweep) {
            $properties = ['SEQUENCE:' . $sweep->sequence];
            if ($sweep->isCancelled()) {
                $properties[] = 'STATUS:CANCELLED';
            }

            $byUid['UID:' . $sweep->id . '@cisteni.bkom.cz'] = $properties;
        }

        $result = [];
        $pending = null;

        foreach (explode("\r\n", $ics) as $line) {
            $isContinuation = $line !== '' && ($line[0] === ' ' || $line[0] === "\t");

            // Vklada se az za celou vlastnost UID vcetne pripadnych pokracovacich
            // radku, aby se nerozbilo skladani radku dle RFC 5545.
            if ($pending !== null && !$isContinuation) {
                array_push($result, ...$pending);
                $pending = null;
            }

            $result[] = $line;

            if (isset($byUid[$line])) {
                $pending = $byUid[$line];
            }
        }

        if ($pending !== null) {
            array_push($result, ...$pending);
        }

        return implode("\r\n", $result);
    }

    /**
     * Doplni X-WR-* hlavicky, ktere eluceo/ical negeneruje, ale kalendarove
     * aplikace podle nich pojmenuji odebrany kalendar.
     */
    private function addCalendarHeaders(string $ics, string $calendarName): string
    {
        $headers = implode("\r\n", array_map(
            fn (string $line): string => $this->fold($line),
            [
                'METHOD:PUBLISH',
                'X-WR-CALNAME:' . $this->escapeText($calendarName),
                'X-WR-CALDESC:' . $this->escapeText('Termíny blokového čištění ulic v Brně (zdroj: BKOM)'),
                'X-WR-TIMEZONE:' . self::TIMEZONE,
                'NAME:' . $this->escapeText($calendarName),
                'REFRESH-INTERVAL;VALUE=DURATION:PT12H',
                'X-PUBLISHED-TTL:PT12H',
            ],
        ));

        return preg_replace(
            '/^(PRODID:[^\r\n]*(?:\r\n[ \t][^\r\n]*)*)/m',
            '$1' . "\r\n" . $headers,
            $ics,
            1,
        ) ?? $ics;
    }

    /**
     * Escapovani textove hodnoty dle RFC 5545.
     *
     * Konce radku se nejdriv sjednoti - samotny CR by v hodnote zustal jako
     * syrovy bajt a nektere parsery ho berou jako konec radku, cimz by se dal
     * z nazvu prichazejiciho z API rozbit soubor.
     */
    /**
     * Slozi radek dle RFC 5545: nejvyse 75 oktetu, pokracovani zacina mezerou.
     *
     * Hlavicky si vkladame do hotoveho vystupu sami, takze se na ne skladani
     * z knihovny nevztahuje - dlouhy nazev ulice by jinak vyrobil radek, ktery
     * prisnejsi parsery odmitnou. Deli se po celych UTF-8 znacich.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= self::MAX_LINE_OCTETS) {
            return $line;
        }

        $chunks = [];
        $current = '';
        $limit = self::MAX_LINE_OCTETS;

        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $chunks[] = $current;
                $current = '';
                // Pokracovaci radek zacina mezerou, ktera se do limitu pocita.
                $limit = self::MAX_LINE_OCTETS - 1;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        $first = array_shift($chunks);

        return $first . ($chunks === [] ? '' : "\r\n " . implode("\r\n ", $chunks));
    }

    private function escapeText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace(["\\", ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }
}
