<?php

declare(strict_types=1);

namespace CisteniBkom\Tests;

use CisteniBkom\BkomClient;
use CisteniBkom\IcsGenerator;
use CisteniBkom\Street;
use CisteniBkom\Sweep;
use PHPUnit\Framework\TestCase;

final class IcsGeneratorTest extends TestCase
{
    /**
     * @return array<string, Sweep>
     */
    private function sweeps(): array
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/sweeps.json');
        self::assertIsString($raw);

        return BkomClient::parseSweeps(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['sweeps']);
    }

    private function street(): Street
    {
        return Street::fromApi([
            'id' => '2526',
            'name' => 'Křídlovická',
            'searchName' => 'kridlovicka',
            'cityPart' => [['id' => '22', 'name' => 'Střed']],
        ]);
    }

    private function generate(?Sweep $sweep = null): string
    {
        $sweep ??= $this->sweeps()['91176'];

        return (new IcsGenerator())->generate([$sweep], 'Čištění – Křídlovická', $this->street());
    }

    public function testConvertsUtcToPragueLocalTime(): void
    {
        // API hlasi from 08:00Z a fromToText "14. 9. 2026, 10:00 - 14:30".
        $ics = $this->generate();

        self::assertStringContainsString('DTSTART;TZID=Europe/Prague:20260914T100000', $ics);
        self::assertStringContainsString('DTEND;TZID=Europe/Prague:20260914T143000', $ics);
    }

    public function testUsesStableUid(): void
    {
        self::assertStringContainsString('UID:91176@cisteni.bkom.cz', $this->generate());
    }

    public function testIncludesSummaryLocationAndGeo(): void
    {
        $ics = $this->generate();

        self::assertStringContainsString('SUMMARY:Blokové čištění: Křídlovická v úseku Nové Sady-viadukt', $this->unfold($ics));
        self::assertStringContainsString('Křídlovická\, Brno', $this->unfold($ics));
        self::assertStringContainsString('GEO:49.185353;16.603785', $ics);
    }

    public function testAddsReminderBeforeStart(): void
    {
        $ics = $this->generate();

        self::assertStringContainsString('BEGIN:VALARM', $ics);
        self::assertStringContainsString('TRIGGER:-PT18H', $ics);
        self::assertStringContainsString('ACTION:DISPLAY', $ics);
    }

    public function testMarksCancelledSweep(): void
    {
        $cancelled = $this->sweeps()['91176']->withStatus(Sweep::STATUS_CANCELLED, '2026-09-06T12:00:00Z');
        $ics = $this->generate($cancelled);

        self::assertStringContainsString('STATUS:CANCELLED', $ics);
        self::assertStringContainsString('[ZRUSENO]', $this->unfold($ics));
        // Zruseny termin uz nema budit upozorneni.
        self::assertStringNotContainsString('BEGIN:VALARM', $ics);
    }

    public function testCancelledStatusDoesNotBreakFoldedSummary(): void
    {
        $cancelled = $this->sweeps()['91176']->withStatus(Sweep::STATUS_CANCELLED, '2026-09-06T12:00:00Z');
        $ics = $this->generate($cancelled);

        // STATUS musi byt samostatna vlastnost az za celym slozenym SUMMARY,
        // ne vlozena doprostred jeho pokracovacich radku.
        $properties = explode("\r\n", $this->unfold($ics));

        self::assertContains('STATUS:CANCELLED', $properties);
        self::assertSame(1, substr_count($ics, 'STATUS:CANCELLED'));

        $statusIndex = array_search('STATUS:CANCELLED', $properties, true);
        self::assertIsInt($statusIndex);
        self::assertStringStartsWith('SUMMARY:[ZRUSENO] Blokové čištění:', $properties[$statusIndex - 1]);
    }

    public function testAddsCalendarNameHeaders(): void
    {
        $ics = $this->generate();

        self::assertStringContainsString('X-WR-CALNAME:Čištění – Křídlovická', $ics);
        self::assertStringContainsString('X-WR-TIMEZONE:Europe/Prague', $ics);
        self::assertStringContainsString('REFRESH-INTERVAL;VALUE=DURATION:PT12H', $ics);
        // Hlavicky patri do VCALENDAR, ne az za prvni VEVENT.
        self::assertLessThan(strpos($ics, 'BEGIN:VEVENT'), strpos($ics, 'X-WR-CALNAME'));
    }

    public function testFallsBackToStreetCoordinatesWhenSectionHasNoWaypoints(): void
    {
        $street = Street::fromApi([
            'id' => '3254',
            'name' => 'Srnčí',
            'searchName' => 'srnci',
            'bounds' => ['sw' => ['lat' => 49.2, 'lng' => 16.7], 'ne' => ['lat' => 49.3, 'lng' => 16.8]],
        ]);

        $ics = (new IcsGenerator())->generate([$this->sweeps()['89309']], 'Čištění – Srnčí', $street);

        self::assertStringContainsString('GEO:49.250000;16.750000', $ics);
    }

    public function testIncludesTimezoneDefinition(): void
    {
        $ics = $this->generate();

        self::assertStringContainsString('BEGIN:VTIMEZONE', $ics);
        self::assertStringContainsString('TZID:Europe/Prague', $ics);
        self::assertStringContainsString('BEGIN:DAYLIGHT', $ics);
        self::assertStringContainsString('BEGIN:STANDARD', $ics);
    }

    public function testProducesValidCalendarEnvelope(): void
    {
        $ics = $this->generate();

        self::assertStringStartsWith('BEGIN:VCALENDAR', $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
    }

    /**
     * Rozbali RFC 5545 skladani radku, aby se dal hledat cely text.
     */
    private function unfold(string $ics): string
    {
        return str_replace(["\r\n ", "\r\n\t"], '', $ics);
    }
}
