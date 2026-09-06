<?php

declare(strict_types=1);

namespace CisteniBkom;

/**
 * Jedno blokove cisteni jednoho useku ulice.
 */
final class Sweep
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly string $sectionId,
        public readonly string $sectionName,
        public readonly string $streetId,
        public readonly ?float $lat,
        public readonly ?float $lon,
        public readonly string $status,
        public readonly ?string $cancelledAt = null,
    ) {
    }

    /**
     * Vytvori Sweep z jednoho prvku pole "sweeps" z /api/sweep/map.
     *
     * Waypointy se zahazuji, ponechava se jen prvni bod pro GEO.
     *
     * @param array<string, mixed> $data
     */
    public static function fromApi(array $data): self
    {
        $section = is_array($data['section'] ?? null) ? $data['section'] : [];

        $streetId = (string) ($section['streetID'] ?? '');
        if ($streetId === '') {
            throw new \InvalidArgumentException(
                'Sweep ' . (string) ($data['id'] ?? '?') . ' nema section.streetID.'
            );
        }

        [$lat, $lon] = self::firstWaypoint($section);

        return new self(
            id: (string) $data['id'],
            name: (string) ($data['name'] ?? ''),
            from: self::parseDateTime((string) $data['from']),
            to: self::parseDateTime((string) $data['to']),
            sectionId: (string) ($section['id'] ?? $data['sid'] ?? ''),
            sectionName: (string) ($section['name'] ?? $data['name'] ?? ''),
            streetId: $streetId,
            lat: $lat,
            lon: $lon,
            status: ($data['done'] ?? false) === true ? self::STATUS_DONE : self::STATUS_PLANNED,
        );
    }

    /**
     * Obnovi Sweep ze snapshotu (data/sweeps.json).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            from: self::parseDateTime((string) $data['from']),
            to: self::parseDateTime((string) $data['to']),
            sectionId: (string) $data['sectionId'],
            sectionName: (string) $data['sectionName'],
            streetId: (string) $data['streetId'],
            lat: isset($data['lat']) ? (float) $data['lat'] : null,
            lon: isset($data['lon']) ? (float) $data['lon'] : null,
            status: (string) $data['status'],
            cancelledAt: isset($data['cancelledAt']) ? (string) $data['cancelledAt'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'from' => $this->from->format('Y-m-d\TH:i:s\Z'),
            'to' => $this->to->format('Y-m-d\TH:i:s\Z'),
            'sectionId' => $this->sectionId,
            'sectionName' => $this->sectionName,
            'streetId' => $this->streetId,
            'lat' => $this->lat,
            'lon' => $this->lon,
            'status' => $this->status,
        ];

        if ($this->cancelledAt !== null) {
            $data['cancelledAt'] = $this->cancelledAt;
        }

        return $data;
    }

    public function withStatus(string $status, ?string $cancelledAt = null): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->from,
            $this->to,
            $this->sectionId,
            $this->sectionName,
            $this->streetId,
            $this->lat,
            $this->lon,
            $status,
            $cancelledAt ?? $this->cancelledAt,
        );
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Porovnava vecny obsah, ne status ani cancelledAt.
     */
    public function hasSameContent(self $other): bool
    {
        return $this->name === $other->name
            && $this->from->getTimestamp() === $other->from->getTimestamp()
            && $this->to->getTimestamp() === $other->to->getTimestamp()
            && $this->sectionId === $other->sectionId
            && $this->sectionName === $other->sectionName
            && $this->streetId === $other->streetId;
    }

    private static function parseDateTime(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Neplatne datum: {$value}", 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $section
     * @return array{0: float|null, 1: float|null}
     */
    private static function firstWaypoint(array $section): array
    {
        $waypoints = $section['waypoints'] ?? null;
        if (!is_array($waypoints) || $waypoints === []) {
            return [null, null];
        }

        $first = reset($waypoints);
        if (!is_array($first) || !isset($first['lat'], $first['lon'])) {
            return [null, null];
        }

        return [(float) $first['lat'], (float) $first['lon']];
    }
}
