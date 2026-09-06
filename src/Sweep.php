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

    /** Delsi "uklid" nez tyden je poskozeny zaznam, ne realny termin. */
    private const MAX_DURATION_SECONDS = 7 * 24 * 3600;

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

        $streetId = self::safeId((string) ($section['streetID'] ?? ''), 'section.streetID');

        [$lat, $lon] = self::firstWaypoint($section);

        $from = self::parseDateTime((string) $data['from']);
        $to = self::normalizeEnd($from, self::parseDateTime((string) $data['to']));

        return new self(
            id: self::safeId((string) $data['id'], 'id'),
            name: (string) ($data['name'] ?? ''),
            from: $from,
            to: $to,
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
        foreach (['id', 'name', 'from', 'to', 'sectionId', 'sectionName', 'streetId', 'status'] as $key) {
            if (!isset($data[$key]) || !is_scalar($data[$key])) {
                throw new \InvalidArgumentException("Zaznam ve snapshotu nema klic \"{$key}\".");
            }
        }

        $status = (string) $data['status'];
        if (!in_array($status, [self::STATUS_PLANNED, self::STATUS_DONE, self::STATUS_CANCELLED], true)) {
            throw new \InvalidArgumentException("Neznamy status ve snapshotu: \"{$status}\".");
        }

        $from = self::parseDateTime((string) $data['from']);
        $to = self::normalizeEnd($from, self::parseDateTime((string) $data['to']));

        return new self(
            id: self::safeId((string) $data['id'], 'id'),
            name: (string) $data['name'],
            from: $from,
            to: $to,
            sectionId: (string) $data['sectionId'],
            sectionName: (string) $data['sectionName'],
            streetId: self::safeId((string) $data['streetId'], 'streetId'),
            lat: isset($data['lat']) ? (float) $data['lat'] : null,
            lon: isset($data['lon']) ? (float) $data['lon'] : null,
            status: $status,
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

    /**
     * ID z ciziho API konci v nazvu souboru (output/<streetId>.ics) a v UID
     * udalosti, takze se nesmi verit jeho tvaru. Pripousti se jen znaky, ktere
     * neumoznuji vystoupit z adresare ani rozbit strukturu ICS.
     */
    private static function safeId(string $value, string $field): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) !== 1) {
            throw new \InvalidArgumentException("Nepouzitelne {$field}: \"{$value}\".");
        }

        return $value;
    }

    /**
     * Srovna casovy rozsah uklidu.
     *
     * BKOM u nocnich uklidu (typicky parkoviste, 19:00-05:00) uvadi konec se
     * stejnym datem jako zacatek, takze "to" vychazi o 14 hodin driv nez "from".
     * Na jejich webu to nevadi, protoze se vypisuje jen cas; v kalendari by ale
     * vznikla udalost se zapornou delkou. Konec proto posouvame na dalsi den.
     *
     * Co nedava smysl ani po posunu, je poskozeny zaznam.
     */
    private static function normalizeEnd(\DateTimeImmutable $from, \DateTimeImmutable $to): \DateTimeImmutable
    {
        if ($to < $from) {
            $to = $to->modify('+1 day');
        }

        if ($to < $from) {
            throw new \InvalidArgumentException('Konec uklidu predchazi jeho zacatku.');
        }

        if ($to->getTimestamp() - $from->getTimestamp() > self::MAX_DURATION_SECONDS) {
            throw new \InvalidArgumentException('Uklid trva nepravdepodobne dlouho.');
        }

        return $to;
    }

    private static function parseDateTime(string $value): \DateTimeImmutable
    {
        // Prazdny retezec by DateTimeImmutable prijal jako "ted" a tise vyrobil
        // udalost se spatnym datem.
        if (trim($value) === '') {
            throw new \InvalidArgumentException('Prazdne datum.');
        }

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
