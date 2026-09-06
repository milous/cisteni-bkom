<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use CisteniBkom\BkomClient;
use CisteniBkom\IcsGenerator;
use CisteniBkom\IndexGenerator;
use CisteniBkom\Sweep;
use CisteniBkom\SweepStorage;

// Configuration
$snapshotPath = __DIR__ . '/data/sweeps.json';
$outputDir = __DIR__ . '/output';
$publicDir = __DIR__ . '/public';

// Okno, ve kterem se stahuje a ve kterem se detekuji zrusene terminy.
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$windowFrom = $now->modify('-1 year');
$windowTo = $now->modify('+2 years');

// Volitelne omezeni pro lokalni ladeni: CISTENI_STREET_IDS=2526,1234
$onlyStreetIds = array_filter(array_map(
    'trim',
    explode(',', (string) getenv('CISTENI_STREET_IDS')),
));

echo "Starting sweep calendar sync...\n";

try {
    $client = new BkomClient();

    // Step 1: Stahnout uklidy a ciselnik ulic
    echo "Fetching sweeps from BKOM...\n";
    $fresh = $client->fetchSweeps($windowFrom, $windowTo);
    echo 'Fetched ' . count($fresh) . " sweeps.\n";

    echo "Fetching street list...\n";
    $streets = $client->fetchStreets();
    echo 'Fetched ' . count($streets) . " streets.\n";

    // Step 2: Slouceni se snapshotem - detekce zrusenych terminu
    echo "Syncing with snapshot...\n";
    $storage = new SweepStorage($snapshotPath);
    $result = $storage->sync($fresh, $windowFrom, $windowTo, $now);
    $storage->save($result['sweeps']);
    printf(
        "Snapshot: %d sweeps (+%d new, %d updated, %d cancelled, %d pruned).\n",
        count($result['sweeps']),
        $result['added'],
        $result['updated'],
        $result['cancelled'],
        $result['pruned'],
    );

    // Step 3: Seskupit podle ulice
    $byStreet = [];
    foreach ($result['sweeps'] as $sweep) {
        if ($onlyStreetIds !== [] && !in_array($sweep->streetId, $onlyStreetIds, true)) {
            continue;
        }
        $byStreet[$sweep->streetId][] = $sweep;
    }

    foreach ($byStreet as $streetId => $sweeps) {
        usort($sweeps, static fn (Sweep $a, Sweep $b): int => $a->from <=> $b->from);
        $byStreet[$streetId] = $sweeps;
    }

    if ($byStreet === []) {
        echo "WARNING: No sweeps to generate! Check the API response.\n";
        exit(1);
    }

    // Step 4: Vygenerovat ICS pro kazdou ulici a jeden souhrnny
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    echo "Generating ICS files...\n";
    $generator = new IcsGenerator();

    foreach ($byStreet as $streetId => $sweeps) {
        $street = $streets[$streetId] ?? null;
        $name = $street?->name ?? $sweeps[0]->sectionName;

        file_put_contents(
            $outputDir . '/' . $streetId . '.ics',
            $generator->generate($sweeps, 'Čištění – ' . $name, $street),
        );
    }

    $all = $result['sweeps'];
    usort($all, static fn (Sweep $a, Sweep $b): int => $a->from <=> $b->from);
    file_put_contents(
        $outputDir . '/all.ics',
        $generator->generate($all, 'Blokové čištění Brno – vše'),
    );

    // Step 5: Vyhledavaci stranka
    echo "Generating index page...\n";
    (new IndexGenerator($outputDir, $publicDir))->generate($byStreet, $streets, $now);

    printf(
        "Done! %d street calendars, %d sweeps total.\n",
        count($byStreet),
        count($result['sweeps']),
    );
    echo "Output: {$outputDir}\n";
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
