<?php

declare(strict_types=1);

namespace CisteniBkom\Tests;

use CisteniBkom\Sweep;
use CisteniBkom\SweepStorage;
use PHPUnit\Framework\TestCase;

final class SweepStorageTest extends TestCase
{
    private string $path;

    private \DateTimeImmutable $now;

    private \DateTimeImmutable $windowFrom;

    private \DateTimeImmutable $windowTo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/cisteni-bkom-test-' . uniqid() . '/sweeps.json';
        $this->now = new \DateTimeImmutable('2026-09-06T12:00:00Z');
        $this->windowFrom = $this->now->modify('-1 year');
        $this->windowTo = $this->now->modify('+2 years');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
            rmdir(dirname($this->path));
        }
    }

    private function sweep(string $id, string $from, string $status = Sweep::STATUS_PLANNED): Sweep
    {
        $start = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));

        return new Sweep(
            id: $id,
            name: 'Ulice ' . $id,
            from: $start,
            to: $start->modify('+3 hours'),
            sectionId: 's' . $id,
            sectionName: 'Ulice ' . $id . ' v úseku A-B',
            streetId: '2526',
            lat: 49.1,
            lon: 16.6,
            status: $status,
        );
    }

    /**
     * @param array<string, Sweep> $fresh
     * @return array{sweeps: array<string, Sweep>, added: int, updated: int, cancelled: int, pruned: int}
     */
    private function sync(array $fresh): array
    {
        return (new SweepStorage($this->path))
            ->sync($fresh, $this->windowFrom, $this->windowTo, $this->now);
    }

    /**
     * @param array<string, Sweep> $sweeps
     */
    private function store(array $sweeps): void
    {
        (new SweepStorage($this->path))->save($sweeps);
    }

    public function testAddsNewSweeps(): void
    {
        $result = $this->sync(['1' => $this->sweep('1', '2026-09-14T08:00:00Z')]);

        self::assertSame(1, $result['added']);
        self::assertSame(0, $result['cancelled']);
        self::assertArrayHasKey('1', $result['sweeps']);
    }

    public function testDetectsChangedTime(): void
    {
        $this->store(['1' => $this->sweep('1', '2026-09-14T08:00:00Z')]);

        $result = $this->sync(['1' => $this->sweep('1', '2026-09-14T10:00:00Z')]);

        self::assertSame(0, $result['added']);
        self::assertSame(1, $result['updated']);
        self::assertSame('2026-09-14T10:00:00+00:00', $result['sweeps']['1']->from->format('c'));
    }

    public function testMarksVanishedFutureSweepAsCancelled(): void
    {
        $this->store(['1' => $this->sweep('1', '2026-09-14T08:00:00Z')]);

        $result = $this->sync([]);

        self::assertSame(1, $result['cancelled']);
        self::assertTrue($result['sweeps']['1']->isCancelled());
        self::assertSame('2026-09-06T12:00:00Z', $result['sweeps']['1']->cancelledAt);
    }

    public function testVanishedPastSweepIsNotCancelled(): void
    {
        $this->store(['1' => $this->sweep('1', '2026-08-01T08:00:00Z')]);

        $result = $this->sync([]);

        self::assertSame(0, $result['cancelled']);
        self::assertSame(Sweep::STATUS_DONE, $result['sweeps']['1']->status);
    }

    public function testSweepOutsideWindowIsKeptUntouched(): void
    {
        $this->store(['1' => $this->sweep('1', '2029-05-01T08:00:00Z')]);

        $result = $this->sync([]);

        self::assertSame(0, $result['cancelled']);
        self::assertSame(Sweep::STATUS_PLANNED, $result['sweeps']['1']->status);
    }

    public function testCancelledSweepReturningToPlanCountsAsUpdate(): void
    {
        $this->store(['1' => $this->sweep('1', '2026-09-14T08:00:00Z', Sweep::STATUS_CANCELLED)]);

        $result = $this->sync(['1' => $this->sweep('1', '2026-09-14T08:00:00Z')]);

        self::assertSame(1, $result['updated']);
        self::assertFalse($result['sweeps']['1']->isCancelled());
    }

    public function testPrunesOldCancelledAndHistoricSweeps(): void
    {
        $this->store([
            'old-cancelled' => $this->sweep('old-cancelled', '2026-07-01T08:00:00Z', Sweep::STATUS_CANCELLED),
            'recent-cancelled' => $this->sweep('recent-cancelled', '2026-09-01T08:00:00Z', Sweep::STATUS_CANCELLED),
            'ancient' => $this->sweep('ancient', '2024-05-01T08:00:00Z', Sweep::STATUS_DONE),
            'last-year' => $this->sweep('last-year', '2026-05-01T08:00:00Z', Sweep::STATUS_DONE),
        ]);

        $result = $this->sync([]);

        self::assertSame(2, $result['pruned']);
        self::assertArrayNotHasKey('old-cancelled', $result['sweeps']);
        self::assertArrayNotHasKey('ancient', $result['sweeps']);
        self::assertArrayHasKey('recent-cancelled', $result['sweeps']);
        self::assertArrayHasKey('last-year', $result['sweeps']);
    }

    public function testSavesSortedForStableDiffs(): void
    {
        $storage = new SweepStorage($this->path);
        $storage->save([
            '20' => $this->sweep('20', '2026-09-14T08:00:00Z'),
            '10' => $this->sweep('10', '2026-09-15T08:00:00Z'),
        ]);

        $raw = file_get_contents($this->path);
        self::assertIsString($raw);
        $ids = array_column(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['sweeps'], 'id');

        self::assertSame(['10', '20'], $ids);
        self::assertSame(['10', '20'], array_map('strval', array_keys($storage->load())));
    }

    public function testLoadReturnsEmptyArrayWhenSnapshotMissing(): void
    {
        self::assertSame([], (new SweepStorage($this->path))->load());
    }
}
