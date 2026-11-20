<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Valore atteso per una famiglia (canonical, robots, jsonld) a un livello (configured o resolved).
 * Disponibilita' e valore sono separati: available con valore vuoto non equivale a unavailable.
 */
final class ExpectedValue
{
    /** @var string */
    private $availability;

    /** @var string|array|null */
    private $value;

    /** @var string|null */
    private $source;

    /**
     * @param array<string, mixed> $data chiavi: availability, value, source
     */
    public function __construct(array $data)
    {
        $availability = $data['availability'] ?? null;
        $value = $data['value'] ?? null;
        $source = $data['source'] ?? null;

        States::assertIn('availability', $availability, States::AVAILABILITY_STATES);
        if ($value !== null && !is_string($value) && !is_array($value)) {
            throw new \InvalidArgumentException('value deve essere stringa, array o null');
        }
        if ($source !== null && !is_string($source)) {
            throw new \InvalidArgumentException('source deve essere stringa o null');
        }

        if ($availability === States::UNAVAILABLE) {
            if ($value !== null || $source !== null) {
                throw new \InvalidArgumentException('dato indisponibile: value e source devono essere null');
            }
        } else {
            if ($value === null) {
                throw new \InvalidArgumentException('dato disponibile: value null non ammesso (usare stringa o array vuoti)');
            }
            if ($source === null || $source === '') {
                throw new \InvalidArgumentException('dato disponibile senza fonte');
            }
        }

        $this->availability = $availability;
        $this->value = ValueCopy::detach($value, 'value');
        $this->source = $source;
    }

    public function availability(): string
    {
        return $this->availability;
    }

    /**
     * @return string|array|null
     */
    public function value()
    {
        return $this->value;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'availability' => $this->availability,
            'value' => $this->value,
            'source' => $this->source,
        ];
    }
}
