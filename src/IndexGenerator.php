<?php

declare(strict_types=1);

namespace CisteniBkom;

/**
 * Generuje podklady pro vyhledavaci stranku - streets.json a staticka aktiva.
 */
final class IndexGenerator
{
    public function __construct(
        private readonly string $outputDir,
        private readonly string $publicDir,
    ) {
    }

    /**
     * @param array<string, array<int, Sweep>> $sweepsByStreet
     * @param array<string, Street> $streets
     */
    public function generate(array $sweepsByStreet, array $streets, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->copyAssets();
        $this->writeStreetsJson($sweepsByStreet, $streets, $now);
    }

    /**
     * @param array<string, array<int, Sweep>> $sweepsByStreet
     * @param array<string, Street> $streets
     */
    private function writeStreetsJson(array $sweepsByStreet, array $streets, \DateTimeImmutable $now): void
    {
        $timezone = new \DateTimeZone(IcsGenerator::TIMEZONE);
        $items = [];

        foreach ($sweepsByStreet as $streetId => $sweeps) {
            $street = $streets[$streetId] ?? null;

            $upcoming = array_values(array_filter(
                $sweeps,
                static fn (Sweep $sweep): bool => $sweep->to >= $now && !$sweep->isCancelled(),
            ));
            usort($upcoming, static fn (Sweep $a, Sweep $b): int => $a->from <=> $b->from);

            $next = $upcoming[0] ?? null;
            $name = $street?->name ?? ($sweeps[0]->sectionName ?? $streetId);

            $items[] = [
                'id' => (string) $streetId,
                'name' => $name,
                'search' => Street::normalize($name . ' ' . ($street?->cityPart ?? '')),
                'cityPart' => $street?->cityPart,
                'count' => count($sweeps),
                'upcoming' => count($upcoming),
                'next' => $next?->from->setTimezone($timezone)->format('c'),
                'nextText' => $next !== null ? $this->formatCzech($next, $timezone) : null,
            ];
        }

        // Radi se podle podoby bez diakritiky, aby "Ž" neskoncilo az za "z".
        usort($items, static fn (array $a, array $b): int => [$a['search'], $a['id']] <=> [$b['search'], $b['id']]);

        $payload = [
            'generated' => $now->format('c'),
            'streets' => $items,
        ];

        file_put_contents(
            $this->outputDir . '/streets.json',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    private function formatCzech(Sweep $sweep, \DateTimeZone $timezone): string
    {
        $from = $sweep->from->setTimezone($timezone);
        $to = $sweep->to->setTimezone($timezone);

        return sprintf(
            '%d. %d. %d, %s - %s',
            (int) $from->format('j'),
            (int) $from->format('n'),
            (int) $from->format('Y'),
            $from->format('H:i'),
            $to->format('H:i'),
        );
    }

    private function copyAssets(): void
    {
        if (!is_dir($this->publicDir)) {
            return;
        }

        foreach (glob($this->publicDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                copy($file, $this->outputDir . '/' . basename($file));
            }
        }
    }
}
