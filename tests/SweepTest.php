<?php

declare(strict_types=1);

namespace CisteniBkom\Tests;

use CisteniBkom\BkomClient;
use CisteniBkom\Sweep;
use PHPUnit\Framework\TestCase;

final class SweepTest extends TestCase
{
    /**
     * @return array<int, mixed>
     */
    private function fixture(): array
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/sweeps.json');
        self::assertIsString($raw);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['sweeps'];
    }

    public function testParsesSweepsFromApiResponse(): void
    {
        $sweeps = BkomClient::parseSweeps($this->fixture());

        // Zaznam bez section.streetID se preskoci, nesmi shodit cely sync.
        self::assertSame(['91176', '91219', '89309'], array_map('strval', array_keys($sweeps)));
    }

    public function testExtractsStreetIdFromSection(): void
    {
        $sweeps = BkomClient::parseSweeps($this->fixture());

        self::assertSame('2526', $sweeps['91176']->streetId);
        self::assertSame('3570', $sweeps['91176']->sectionId);
        self::assertSame('Křídlovická v úseku Nové Sady-viadukt', $sweeps['91176']->sectionName);
    }

    public function testKeepsOnlyFirstWaypointAsGeo(): void
    {
        $sweeps = BkomClient::parseSweeps($this->fixture());

        self::assertEqualsWithDelta(49.1853530487531, $sweeps['91176']->lat, 0.0000001);
        self::assertEqualsWithDelta(16.6037853490469, $sweeps['91176']->lon, 0.0000001);

        // Usek bez waypointu nema souradnice.
        self::assertNull($sweeps['89309']->lat);
        self::assertNull($sweeps['89309']->lon);
    }

    public function testDerivesStatusFromDoneFlag(): void
    {
        $sweeps = BkomClient::parseSweeps($this->fixture());

        self::assertSame(Sweep::STATUS_PLANNED, $sweeps['91176']->status);
        self::assertSame(Sweep::STATUS_DONE, $sweeps['89309']->status);
    }

    public function testParsesTimesAsUtc(): void
    {
        $sweeps = BkomClient::parseSweeps($this->fixture());

        self::assertSame('2026-09-14T08:00:00+00:00', $sweeps['91176']->from->format('c'));
        self::assertSame('2026-09-14T12:30:00+00:00', $sweeps['91176']->to->format('c'));
    }

    public function testRoundTripsThroughSnapshotArray(): void
    {
        $original = BkomClient::parseSweeps($this->fixture())['91176'];
        $restored = Sweep::fromArray($original->toArray());

        self::assertTrue($original->hasSameContent($restored));
        self::assertSame($original->status, $restored->status);
        self::assertSame($original->lat, $restored->lat);
    }

    public function testHasSameContentIgnoresStatus(): void
    {
        $sweep = BkomClient::parseSweeps($this->fixture())['91176'];

        self::assertTrue($sweep->hasSameContent($sweep->withStatus(Sweep::STATUS_CANCELLED, '2026-09-06T00:00:00Z')));
    }
}
