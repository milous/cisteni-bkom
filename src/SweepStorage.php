<?php

declare(strict_types=1);

namespace CisteniBkom;

/**
 * Snapshot uklidu v data/sweeps.json.
 *
 * Drzi posledni znamy stav, aby se dal detekovat termin, ktery z API zmizel
 * (zruseny nebo presunuty) - takovy se do kalendare vyda jako STATUS:CANCELLED,
 * aby zmizel i z uz odebranych kalendaru.
 */
final class SweepStorage
{
    /** Jak dlouho po planovanem terminu se zruseny uklid jeste drzi v kalendari. */
    private const CANCELLED_RETENTION_DAYS = 30;

    /** Jak stara historie se v snapshotu jeste uchovava. */
    private const HISTORY_RETENTION_DAYS = 365;

    public function __construct(
        private readonly string $path,
    ) {
    }

    /**
     * @return array<string, Sweep>
     */
    public function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Snapshot {$this->path} je poskozeny: {$e->getMessage()}", 0, $e);
        }

        $items = is_array($data) && isset($data['sweeps']) && is_array($data['sweeps'])
            ? $data['sweeps']
            : [];

        $sweeps = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sweep = Sweep::fromArray($item);
            $sweeps[$sweep->id] = $sweep;
        }

        return $sweeps;
    }

    /**
     * Slouci cerstva data z API se snapshotem.
     *
     * @param array<string, Sweep> $fresh cerstva data z API
     * @param \DateTimeImmutable $windowFrom zacatek stahovaneho okna
     * @param \DateTimeImmutable $windowTo konec stahovaneho okna
     * @return array{sweeps: array<string, Sweep>, added: int, updated: int, cancelled: int, pruned: int}
     */
    public function sync(
        array $fresh,
        \DateTimeImmutable $windowFrom,
        \DateTimeImmutable $windowTo,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $stored = $this->load();

        $added = 0;
        $updated = 0;
        $cancelled = 0;
        $result = [];

        foreach ($fresh as $id => $sweep) {
            $previous = $stored[$id] ?? null;

            if ($previous === null) {
                $added++;
            } elseif (!$previous->hasSameContent($sweep) || $previous->isCancelled()) {
                // Termin se zmenil, nebo se drive zruseny uklid vratil zpet do planu.
                $updated++;
            }

            $result[$id] = $sweep;
        }

        foreach ($stored as $id => $sweep) {
            if (isset($result[$id])) {
                continue;
            }

            // Mimo stahovane okno nemuzeme o zmizeni nic tvrdit - zaznam jen ponechame.
            if ($sweep->from < $windowFrom || $sweep->from > $windowTo) {
                $result[$id] = $sweep;
                continue;
            }

            // Uklid, ktery uz probehl, z API bezne mizi - to neni zruseni.
            if ($sweep->from <= $now) {
                $result[$id] = $sweep->isCancelled()
                    ? $sweep
                    : $sweep->withStatus(Sweep::STATUS_DONE);
                continue;
            }

            if (!$sweep->isCancelled()) {
                $cancelled++;
            }

            $result[$id] = $sweep->isCancelled()
                ? $sweep
                : $sweep->withStatus(Sweep::STATUS_CANCELLED, $now->format('Y-m-d\TH:i:s\Z'));
        }

        $before = count($result);
        $result = $this->prune($result, $now);
        $pruned = $before - count($result);

        return [
            'sweeps' => $result,
            'added' => $added,
            'updated' => $updated,
            'cancelled' => $cancelled,
            'pruned' => $pruned,
        ];
    }

    /**
     * @param array<string, Sweep> $sweeps
     */
    public function save(array $sweeps): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Radit podle ID -> stabilni diffy v gitu.
        ksort($sweeps, SORT_STRING);

        $payload = [
            'sweeps' => array_values(array_map(
                static fn (Sweep $sweep): array => $sweep->toArray(),
                $sweeps,
            )),
        ];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        file_put_contents($this->path, $json . "\n");
    }

    /**
     * Vyhodi stare zaznamy, at snapshot neroste donekonecna.
     *
     * @param array<string, Sweep> $sweeps
     * @return array<string, Sweep>
     */
    private function prune(array $sweeps, \DateTimeImmutable $now): array
    {
        $cancelledCutoff = $now->modify('-' . self::CANCELLED_RETENTION_DAYS . ' days');
        $historyCutoff = $now->modify('-' . self::HISTORY_RETENTION_DAYS . ' days');

        return array_filter(
            $sweeps,
            static function (Sweep $sweep) use ($cancelledCutoff, $historyCutoff): bool {
                if ($sweep->isCancelled()) {
                    return $sweep->from >= $cancelledCutoff;
                }

                return $sweep->from >= $historyCutoff;
            },
        );
    }
}
