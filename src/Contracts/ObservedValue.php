<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Valore osservato per una famiglia (canonical, robots, jsonld) in una singola capture.
 * present: valori osservati non vuoti; absent: osservazione valida e vuota su risposta completa;
 * unobservable: nessuna osservazione (capture bloccata o fallita, o non leggibile).
 */
final class ObservedValue
{
    private const URL_PATTERN = '#^https?://[^\s]+\z#';
    private const ROBOTS_SOURCES = ['meta', 'header'];

    /** @var string */
    private $family;

    /** @var string */
    private $availability;

    /** @var array|null */
    private $value;

    /**
     * @param array<string, mixed> $data chiavi: availability, value
     */
    public function __construct(string $family, array $data, string $captureStatus)
    {
        RuleCatalog::rulesOf($family);
        $availability = $data['availability'] ?? null;
        $value = $data['value'] ?? null;
        States::assertIn($family . '.availability', $availability, States::OBSERVED_AVAILABILITY_STATES);

        if ($availability === States::OBSERVED_ABSENT && $captureStatus !== States::SCAN_OK) {
            throw new \InvalidArgumentException($family . ': availability absent richiede capture ok, capture ' . $captureStatus);
        }
        if (in_array($captureStatus, [States::SCAN_BLOCKED, States::SCAN_FAILED], true) && $availability !== States::OBSERVED_UNOBSERVABLE) {
            throw new \InvalidArgumentException($family . ': capture ' . $captureStatus . ' richiede availability unobservable');
        }

        if ($availability === States::OBSERVED_UNOBSERVABLE) {
            if ($value !== null) {
                throw new \InvalidArgumentException($family . ': availability unobservable richiede value null');
            }
        } elseif ($availability === States::OBSERVED_ABSENT) {
            if ($value !== []) {
                throw new \InvalidArgumentException($family . ': availability absent richiede value lista vuota');
            }
        } else {
            if (!ValueCopy::isList($value) || $value === []) {
                throw new \InvalidArgumentException($family . ': availability present richiede value lista non vuota');
            }
            foreach ($value as $item) {
                self::assertItem($family, $item);
            }
        }

        $this->family = $family;
        $this->availability = $availability;
        $this->value = ValueCopy::detach($value, $family . '.value');
    }

    public function family(): string
    {
        return $this->family;
    }

    public function availability(): string
    {
        return $this->availability;
    }

    /**
     * @return array|null
     */
    public function value(): ?array
    {
        return $this->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['availability' => $this->availability, 'value' => $this->value];
    }

    /**
     * @param mixed $item
     */
    private static function assertItem(string $family, $item): void
    {
        if ($family === 'canonical') {
            if (!is_string($item) || !preg_match(self::URL_PATTERN, $item)) {
                throw new \InvalidArgumentException('canonical: target osservato deve essere URL http(s) assoluto');
            }
            return;
        }
        if ($family === 'robots') {
            if (!is_array($item) || !in_array($item['source'] ?? null, self::ROBOTS_SOURCES, true)
                || !isset($item['bot'], $item['directive']) || !is_string($item['bot']) || $item['bot'] === ''
                || !is_string($item['directive']) || $item['directive'] === '') {
                throw new \InvalidArgumentException('robots: direttiva osservata richiede source meta|header, bot e directive');
            }
            return;
        }
        if (!is_array($item) || !array_key_exists('id', $item) || !($item['id'] === null || (is_string($item['id']) && $item['id'] !== ''))
            || !ValueCopy::isList($item['types'] ?? null) || !is_int($item['script'] ?? null) || $item['script'] < 1) {
            throw new \InvalidArgumentException('jsonld: nodo osservato richiede id (stringa o null), types (lista) e script (intero >= 1)');
        }
    }
}
