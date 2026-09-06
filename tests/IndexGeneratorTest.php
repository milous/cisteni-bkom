<?php

declare(strict_types=1);

namespace CisteniBkom\Tests;

use CisteniBkom\BkomClient;
use CisteniBkom\IndexGenerator;
use CisteniBkom\Street;
use CisteniBkom\Sweep;
use PHPUnit\Framework\TestCase;

final class IndexGeneratorTest extends TestCase
{
    private string $outputDir;

    private string $publicDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/cisteni-bkom-index-' . uniqid();
        $this->outputDir = $base . '/output';
        $this->publicDir = $base . '/public';
        mkdir($this->outputDir, 0755, true);
        mkdir($this->publicDir, 0755, true);
        file_put_contents($this->publicDir . '/index.html', '<html></html>');
    }

    protected function tearDown(): void
    {
        foreach ([$this->outputDir, $this->publicDir] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        rmdir(dirname($this->outputDir));
    }

    public function testFailsLoudlyWhenOutputCannotBeWritten(): void
    {
        $generator = new IndexGenerator($this->outputDir . '/neexistuje', $this->publicDir);

        $this->expectException(\RuntimeException::class);
        // Poskozeny vystup se nesmi tise nasadit - chyba zapisu musi shodit sync.
        @$generator->generate(['2526' => array_values($this->sweeps())], [], new \DateTimeImmutable('2026-09-01T00:00:00Z'));
    }

    /**
     * @return array<string, Sweep>
     */
    private function sweeps(): array
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/sweeps.json');
        self::assertIsString($raw);

        return BkomClient::parseSweeps(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['sweeps']);
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(): array
    {
        $sweeps = $this->sweeps();
        $streets = [
            '2526' => Street::fromApi([
                'id' => '2526',
                'name' => 'Křídlovická',
                'searchName' => 'kridlovicka',
                'cityPart' => [['id' => '22', 'name' => 'Střed']],
            ]),
            '3254' => Street::fromApi(['id' => '3254', 'name' => 'Srnčí', 'searchName' => 'srnci']),
        ];

        $byStreet = [
            '2526' => [$sweeps['91176'], $sweeps['91219']],
            '3254' => [$sweeps['89309']],
        ];

        (new IndexGenerator($this->outputDir, $this->publicDir))
            ->generate($byStreet, $streets, new \DateTimeImmutable('2026-09-06T12:00:00Z'));

        $raw = file_get_contents($this->outputDir . '/streets.json');
        self::assertIsString($raw);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testListsOnlyStreetsWithSweeps(): void
    {
        $data = $this->generate();

        self::assertSame(['2526', '3254'], array_column($data['streets'], 'id'));
    }

    public function testReportsNearestUpcomingSweep(): void
    {
        $streets = array_column($this->generate()['streets'], null, 'id');

        self::assertSame('14. 9. 2026, 10:00 - 14:30', $streets['2526']['nextText']);
        self::assertSame(2, $streets['2526']['upcoming']);

        // Ulice jen s probehlym uklidem nema nejblizsi termin.
        self::assertNull($streets['3254']['nextText']);
        self::assertSame(0, $streets['3254']['upcoming']);
    }

    public function testSearchFieldIsDiacriticsFree(): void
    {
        $streets = array_column($this->generate()['streets'], null, 'id');

        self::assertStringContainsString('kridlovicka', $streets['2526']['search']);
        self::assertStringContainsString('stred', $streets['2526']['search']);
    }

    public function testListsSectionsForStreetCleanedInParts(): void
    {
        $streets = array_column($this->generate()['streets'], null, 'id');

        $sections = $streets['2526']['sections'];

        self::assertCount(2, $sections);
        self::assertNotSame($sections[0]['id'], $sections[1]['id']);
        foreach ($sections as $section) {
            self::assertMatchesRegularExpression('/^2526-[a-f0-9]{8}$/', $section['id']);
        }

        // Radi se podle nejblizsiho terminu.
        self::assertSame('14. 9. 2026, 10:00 - 14:30', $sections[0]['nextText']);
        self::assertSame('Křídlovická v úseku Nové Sady-viadukt', $sections[0]['name']);
    }

    public function testStreetCleanedAsWholeHasNoSections(): void
    {
        $streets = array_column($this->generate()['streets'], null, 'id');

        // Jediny usek by dal kalendar totozny s kalendarem ulice - nema smysl.
        self::assertSame([], $streets['3254']['sections']);
    }

    public function testCopiesPublicAssets(): void
    {
        $this->generate();

        self::assertFileExists($this->outputDir . '/index.html');
    }
}
