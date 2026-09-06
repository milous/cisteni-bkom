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

    /**
     * Nazvy useku a ulic prichazi z ciziho API a vklada se z nich text do ICS.
     * Zadny znak z nich nesmi zalozit novy radek souboru.
     *
     * @return array<string, array{0: string}>
     */
    public static function lineBreakPayloadProvider(): array
    {
        return [
            'CRLF' => ["Ulice\r\nBEGIN:VEVENT\r\nUID:evil@x\r\nSUMMARY:PODVRZENO"],
            'samotny CR' => ["Ulice\rSUMMARY:PODVRZENO"],
            'samotny LF' => ["Ulice\nSUMMARY:PODVRZENO"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lineBreakPayloadProvider')]
    public function testTextFromApiCannotInjectIcsLines(string $payload): void
    {
        $sweep = $this->sweeps()['91176'];
        $item = ['id' => '91176', 'name' => $payload, 'from' => '2026-09-14T08:00:00.000Z',
            'to' => '2026-09-14T12:30:00.000Z', 'sid' => '3570',
            'section' => ['id' => '3570', 'name' => $payload, 'streetID' => '2526', 'waypoints' => []]];
        $sweep = BkomClient::parseSweeps([$item])['91176'];
        $street = Street::fromApi(['id' => '2526', 'name' => $payload, 'searchName' => 'x']);

        $ics = (new IcsGenerator())->generate([$sweep], 'Čištění – ' . $payload, $street);

        // Zadny syrovy CR ani LF mimo ukonceni radku CRLF.
        self::assertSame(0, preg_match_all("/\r(?!\n)/", $ics), 'syrovy CR v souboru');
        self::assertSame(0, preg_match_all("/(?<!\r)\n/", $ics), 'syrovy LF v souboru');

        // A hlavne zadna podvrzena udalost ani vlastnost navic.
        $lines = explode("\r\n", $ics);
        self::assertCount(1, preg_grep('/^BEGIN:VEVENT$/', $lines));
        self::assertCount(0, preg_grep('/^UID:evil@x$/', $lines));
        self::assertCount(0, preg_grep('/^SUMMARY:PODVRZENO$/', $lines));
    }

    public function testNameFromApiCannotFakeCancelledStatus(): void
    {
        $item = ['id' => '91176', 'name' => '[ZRUSENO] Křídlovická', 'from' => '2026-09-14T08:00:00.000Z',
            'to' => '2026-09-14T12:30:00.000Z', 'sid' => '3570',
            'section' => ['id' => '3570', 'name' => '[ZRUSENO] Křídlovická', 'streetID' => '2526', 'waypoints' => []]];
        $sweep = BkomClient::parseSweeps([$item])['91176'];

        $ics = (new IcsGenerator())->generate([$sweep], 'Čištění – Křídlovická', $this->street());

        // Termin plati, takze se nesmi tvarit jako zruseny - ani statusem, ani textem.
        self::assertStringNotContainsString('STATUS:CANCELLED', $ics);
        self::assertStringNotContainsString('ZRUSENO', $this->unfold($ics));
    }

    public function testCancelledStatusIsAddedEvenWhenSummaryIsRewritten(): void
    {
        $cancelled = $this->sweeps()['91176']->withStatus(Sweep::STATUS_CANCELLED, '2026-09-06T12:00:00Z');
        $ics = (new IcsGenerator())->generate([$cancelled, $this->sweeps()['91219']], 'Čištění – Křídlovická', $this->street());

        // Prave jeden ze dvou terminu je zruseny.
        self::assertSame(1, substr_count($ics, 'STATUS:CANCELLED'));

        $lines = explode("\r\n", $ics);
        $statusIndex = array_search('STATUS:CANCELLED', $lines, true);
        self::assertIsInt($statusIndex);
        self::assertSame('UID:91176@cisteni.bkom.cz', $lines[$statusIndex - 1]);
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

    public function testFoldsLongCalendarNameHeader(): void
    {
        $name = 'Čištění – Žebětínská před bytovým domem Žebětínská 72,74 a u Sběrného střediska odpadu';
        $ics = (new IcsGenerator())->generate([$this->sweeps()['91176']], $name, $this->street());

        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), "Radek presahuje 75 oktetu: {$line}");
        }

        // Rozbaleni musi dat zpet puvodni text vcetne escapovane carky.
        self::assertStringContainsString(
            'X-WR-CALNAME:' . str_replace(',', '\,', $name),
            $this->unfold($ics),
        );
    }

    public function testFoldingNeverSplitsMultibyteCharacter(): void
    {
        // Sam diakriticke znaky: kazde deleni na hranici 75 oktetu by pri
        // delceni po bajtech rozseklo vicebajtovy znak.
        $ics = (new IcsGenerator())->generate([$this->sweeps()['91176']], str_repeat('ě', 120), $this->street());

        foreach (explode("\r\n", $ics) as $line) {
            self::assertSame($line, mb_convert_encoding($line, 'UTF-8', 'UTF-8'), 'Rozseknuty UTF-8 znak.');
        }
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
