<?php

declare(strict_types=1);

namespace CisteniBkom;

/**
 * Klient nad verejnym API https://cisteni.bkom.cz.
 *
 * Pozor: endpoint /api/sweep/map ignoruje from/to, pokud je zadan streetID.
 * Pro davkove stahovani se streetID nepouziva a filtruje se az lokalne.
 */
final class BkomClient
{
    private const BASE_URL = 'https://cisteni.bkom.cz';
    private const USER_AGENT = 'cisteni-bkom-calendar (+https://github.com/milous/cisteni-bkom)';
    private const TIMEOUT_SECONDS = 120;
    private const MAX_ATTEMPTS = 3;

    /** Minimalni pocet zaznamu, pod kterym povazujeme odpoved za rozbitou. */
    private const MIN_SWEEPS = 1;
    private const MIN_STREETS = 100;

    public function __construct(
        private readonly string $baseUrl = self::BASE_URL,
    ) {
    }

    /**
     * @return array<string, Sweep> klicovano ID uklidu
     */
    public function fetchSweeps(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $url = $this->baseUrl . '/api/sweep/map?' . http_build_query([
            'from' => $this->formatApiDate($from),
            'to' => $this->formatApiDate($to),
        ]);

        $data = $this->getJson($url);

        if (!isset($data['sweeps']) || !is_array($data['sweeps'])) {
            throw new \RuntimeException('Odpoved /api/sweep/map neobsahuje pole "sweeps".');
        }

        $sweeps = self::parseSweeps($data['sweeps']);

        if (count($sweeps) < self::MIN_SWEEPS) {
            throw new \RuntimeException(
                'API vratilo 0 uklidu - pravdepodobne se zmenila struktura nebo je vypadek.'
            );
        }

        return $sweeps;
    }

    /**
     * @return array<string, Street> klicovano ID ulice
     */
    public function fetchStreets(): array
    {
        $data = $this->getJson($this->baseUrl . '/api/street');

        if (!isset($data['streets']) || !is_array($data['streets'])) {
            throw new \RuntimeException('Odpoved /api/street neobsahuje pole "streets".');
        }

        $streets = [];
        foreach ($data['streets'] as $item) {
            if (!is_array($item) || !isset($item['id'], $item['name'])) {
                continue;
            }
            $street = Street::fromApi($item);
            $streets[$street->id] = $street;
        }

        if (count($streets) < self::MIN_STREETS) {
            throw new \RuntimeException(
                'API vratilo jen ' . count($streets) . ' ulic - pravdepodobne se zmenila struktura.'
            );
        }

        return $streets;
    }

    /**
     * Prevede syrove pole "sweeps" na DTO. Vadne zaznamy preskoci.
     *
     * @param array<int, mixed> $items
     * @return array<string, Sweep>
     */
    public static function parseSweeps(array $items): array
    {
        $sweeps = [];

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'], $item['from'], $item['to'])) {
                continue;
            }

            try {
                $sweep = Sweep::fromApi($item);
            } catch (\InvalidArgumentException) {
                // Zaznam bez pouzitelne ulice nebo data preskocime, jeden vadny radek
                // nesmi shodit cely sync.
                continue;
            }

            $sweeps[$sweep->id] = $sweep;
        }

        return $sweeps;
    }

    private function formatApiDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url): array
    {
        $body = $this->get($url);

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Odpoved z {$url} neni platny JSON: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($data)) {
            throw new \RuntimeException("Odpoved z {$url} neni JSON objekt.");
        }

        return $data;
    }

    private function get(string $url): string
    {
        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $curl = curl_init($url);
            if ($curl === false) {
                throw new \RuntimeException('Nepodarilo se inicializovat cURL.');
            }

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_ENCODING => '',
            ]);

            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            unset($curl);

            if (is_string($body) && $status >= 200 && $status < 300) {
                return $body;
            }

            $lastError = $error !== '' ? $error : "HTTP {$status}";

            if ($attempt < self::MAX_ATTEMPTS) {
                sleep(2 ** $attempt);
            }
        }

        throw new \RuntimeException("Stazeni {$url} selhalo po " . self::MAX_ATTEMPTS . " pokusech: {$lastError}");
    }
}
