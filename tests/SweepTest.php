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

    /**
     * ID z API konci v nazvu souboru output/<streetId>.ics, takze nesmi
     * obsahovat lomitka ani tecky, kterymi by slo vystoupit z adresare.
     *
     * @return array<string, array{0: string}>
     */
    public static function unsafeIdProvider(): array
    {
        return [
            'traversal' => ['../../../../tmp/pwn'],
            'lomitko' => ['2526/x'],
            'tecky' => ['..'],
            'prazdne' => [''],
            'mezera' => ['25 26'],
            'novy radek' => ["2526\nSUMMARY:x"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeIdProvider')]
    public function testRejectsUnsafeStreetId(string $streetId): void
    {
        $item = $this->fixture()[0];
        $item['section']['streetID'] = $streetId;

        self::assertSame([], BkomClient::parseSweeps([$item]));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeIdProvider')]
    public function testRejectsUnsafeSweepId(string $sweepId): void
    {
        $item = $this->fixture()[0];
        $item['id'] = $sweepId;

        self::assertSame([], BkomClient::parseSweeps([$item]));
    }

    /**
     * BKOM u nocnich uklidu parkovist uvadi konec se stejnym datem jako zacatek
     * (19:00-05:00 vyjde jako from 17:00Z, to 03:00Z tehoz dne). Takovy zaznam
     * se nesmi zahodit - jsou to prave mista, kde lidi nechavaji auta pres noc.
     */
    public function testRollsOvernightSweepEndToNextDay(): void
    {
        $item = $this->fixture()[0];
        $item['from'] = '2026-09-29T17:00:00.000Z';
        $item['to'] = '2026-09-29T03:00:00.000Z';

        $sweep = BkomClient::parseSweeps([$item])['91176'];

        self::assertSame('2026-09-30T03:00:00+00:00', $sweep->to->format('c'));
        self::assertSame(10 * 3600, $sweep->to->getTimestamp() - $sweep->from->getTimestamp());
    }

    public function testRejectsSweepEndingBeforeItStartsEvenAfterRollover(): void
    {
        $item = $this->fixture()[0];
        $item['to'] = '2026-09-01T06:00:00.000Z';

        self::assertSame([], BkomClient::parseSweeps([$item]));
    }

    public function testRejectsImplausiblyLongSweep(): void
    {
        $item = $this->fixture()[0];
        $item['to'] = '2026-11-14T08:00:00.000Z';

        self::assertSame([], BkomClient::parseSweeps([$item]));
    }

    public function testRejectsEmptyDate(): void
    {
        $item = $this->fixture()[0];
        $item['from'] = '';

        self::assertSame([], BkomClient::parseSweeps([$item]));
    }

    public function testRejectsSnapshotRecordWithUnknownStatus(): void
    {
        $data = BkomClient::parseSweeps($this->fixture())['91176']->toArray();
        $data['status'] = 'whatever';

        $this->expectException(\InvalidArgumentException::class);
        Sweep::fromArray($data);
    }

    /**
     * @param array<int, array{globalID?: string, lat?: float, lon?: float}> $waypoints
     * @return array<string, mixed>
     */
    private function itemWith(string $id, string $sectionName, array $waypoints): array
    {
        $item = $this->fixture()[0];
        $item['id'] = $id;
        $item['section']['name'] = $sectionName;
        $item['section']['waypoints'] = $waypoints;

        return $item;
    }

    /**
     * BKOM prideluje stejnemu useku pri kazdem terminu jine sid a nazev mezi
     * terminy prepisuje. Klic musi drzet na geometrii.
     */
    public function testSectionKeyIgnoresChangingIdAndName(): void
    {
        $geometry = [
            ['globalID' => '1103437250', 'lat' => 49.148, 'lon' => 16.664],
            ['globalID' => '1103437251', 'lat' => 49.149, 'lon' => 16.665],
        ];

        $april = $this->itemWith('4792', 'Úhlehle', $geometry);
        $april['section']['id'] = '4792';

        $october = $this->itemWith('452', 'Úlehle', array_reverse($geometry));
        $october['section']['id'] = '452';

        $sweeps = BkomClient::parseSweeps([$april, $october]);

        self::assertSame($sweeps['4792']->sectionKey, $sweeps['452']->sectionKey);
    }

    /**
     * Bitesska ma dva ruzne useky pod stejnym nazvem - slouceni by lidem
     * poslalo upozorneni na cast ulice, kde neparkuji.
     */
    public function testSectionKeySeparatesDifferentSectionsWithSameName(): void
    {
        $north = $this->itemWith('1', 'Bítešská', [['globalID' => '1103714390', 'lat' => 49.177, 'lon' => 16.563]]);
        $south = $this->itemWith('2', 'Bítešská', [['globalID' => '1103713984', 'lat' => 49.168, 'lon' => 16.548]]);

        $sweeps = BkomClient::parseSweeps([$north, $south]);

        self::assertNotSame($sweeps['1']->sectionKey, $sweeps['2']->sectionKey);
    }

    public function testSectionKeyFallsBackToCoordinatesWithoutGlobalId(): void
    {
        $a = $this->itemWith('1', 'Bez ID', [['lat' => 49.1, 'lon' => 16.6]]);
        $b = $this->itemWith('2', 'Jiny nazev', [['lat' => 49.1, 'lon' => 16.6]]);
        $c = $this->itemWith('3', 'Bez ID', [['lat' => 49.2, 'lon' => 16.7]]);

        $sweeps = BkomClient::parseSweeps([$a, $b, $c]);

        self::assertSame($sweeps['1']->sectionKey, $sweeps['2']->sectionKey);
        self::assertNotSame($sweeps['1']->sectionKey, $sweeps['3']->sectionKey);
    }

    public function testSectionKeyFallsBackToNameWithoutGeometry(): void
    {
        $sweeps = BkomClient::parseSweeps([
            $this->itemWith('1', 'Srnčí', []),
            $this->itemWith('2', 'srnčí ', []),
            $this->itemWith('3', 'Jiná', []),
        ]);

        self::assertSame($sweeps['1']->sectionKey, $sweeps['2']->sectionKey);
        self::assertNotSame($sweeps['1']->sectionKey, $sweeps['3']->sectionKey);
    }

    public function testSectionKeyIsSafeForUseInFileName(): void
    {
        foreach (BkomClient::parseSweeps($this->fixture()) as $sweep) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{8}$/', $sweep->sectionKey);
        }
    }

    public function testSectionKeyIsNotPartOfContentComparison(): void
    {
        // Prekresleni trasy udalost pro uzivatele nemeni, jen ji presune do
        // jineho kalendare useku - nesmi zvysovat revizi.
        $sweeps = BkomClient::parseSweeps([
            $this->itemWith('1', 'Ulice', [['globalID' => '1']]),
            $this->itemWith('1', 'Ulice', [['globalID' => '2']]),
        ]);
        $one = BkomClient::parseSweeps([$this->itemWith('1', 'Ulice', [['globalID' => '1']])])['1'];
        $two = BkomClient::parseSweeps([$this->itemWith('1', 'Ulice', [['globalID' => '2']])])['1'];

        self::assertNotSame($one->sectionKey, $two->sectionKey);
        self::assertTrue($one->hasSameContent($two));
    }

    public function testHasSameContentIgnoresStatus(): void
    {
        $sweep = BkomClient::parseSweeps($this->fixture())['91176'];

        self::assertTrue($sweep->hasSameContent($sweep->withStatus(Sweep::STATUS_CANCELLED, '2026-09-06T00:00:00Z')));
    }
}
