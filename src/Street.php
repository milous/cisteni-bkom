<?php

declare(strict_types=1);

namespace CisteniBkom;

/**
 * Ulice z ciselniku /api/street.
 */
final class Street
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $searchName,
        public readonly ?string $cityPart,
        public readonly ?float $lat,
        public readonly ?float $lon,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromApi(array $data): self
    {
        [$lat, $lon] = self::boundsCenter($data['bounds'] ?? null);

        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            searchName: self::normalize((string) ($data['searchName'] ?? '') ?: (string) $data['name']),
            cityPart: self::cityPart($data['cityPart'] ?? null),
            lat: $lat,
            lon: $lon,
        );
    }

    /**
     * Prevede text na podobu bez diakritiky a malymi pismeny pro fulltext.
     */
    public static function normalize(string $value): string
    {
        $map = [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
            'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
            'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u',
        ];

        return strtr(mb_strtolower($value, 'UTF-8'), $map);
    }

    private static function cityPart(mixed $cityPart): ?string
    {
        if (!is_array($cityPart) || $cityPart === []) {
            return null;
        }

        $names = [];
        foreach ($cityPart as $part) {
            if (is_array($part) && isset($part['name'])) {
                $names[] = (string) $part['name'];
            }
        }

        return $names === [] ? null : implode(', ', $names);
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private static function boundsCenter(mixed $bounds): array
    {
        if (!is_array($bounds) || !isset($bounds['sw']['lat'], $bounds['sw']['lng'], $bounds['ne']['lat'], $bounds['ne']['lng'])) {
            return [null, null];
        }

        return [
            ((float) $bounds['sw']['lat'] + (float) $bounds['ne']['lat']) / 2,
            ((float) $bounds['sw']['lng'] + (float) $bounds['ne']['lng']) / 2,
        ];
    }
}
