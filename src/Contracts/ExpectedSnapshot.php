<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Snapshot atteso di una pagina, con livelli separati configured e resolved per ogni famiglia.
 */
final class ExpectedSnapshot
{
    /** @var ExpectedValue[] */
    private $configured;

    /** @var ExpectedValue[] */
    private $resolved;

    /**
     * @param array<string, mixed> $data chiavi: configured, resolved; ognuna con canonical, robots, jsonld
     */
    public function __construct(array $data)
    {
        $this->configured = self::readLayer($data, 'configured');
        $this->resolved = self::readLayer($data, 'resolved');
    }

    public function configured(string $family): ExpectedValue
    {
        return $this->valueOf($this->configured, $family);
    }

    public function resolved(string $family): ExpectedValue
    {
        return $this->valueOf($this->resolved, $family);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $out = ['configured' => [], 'resolved' => []];
        foreach (RuleCatalog::families() as $family) {
            $out['configured'][$family] = $this->configured[$family]->toArray();
            $out['resolved'][$family] = $this->resolved[$family]->toArray();
        }
        return $out;
    }

    /**
     * @return ExpectedValue[]
     */
    private static function readLayer(array $data, string $layer): array
    {
        $block = $data[$layer] ?? null;
        if (!is_array($block)) {
            throw new \InvalidArgumentException('expected.' . $layer . ' mancante');
        }
        $out = [];
        foreach (RuleCatalog::families() as $family) {
            if (!isset($block[$family]) || !is_array($block[$family])) {
                throw new \InvalidArgumentException('expected.' . $layer . '.' . $family . ' mancante');
            }
            $out[$family] = new ExpectedValue($block[$family]);
        }
        if (count($block) !== count($out)) {
            throw new \InvalidArgumentException('chiavi estranee in expected.' . $layer);
        }
        return $out;
    }

    /**
     * @param ExpectedValue[] $layer
     */
    private function valueOf(array $layer, string $family): ExpectedValue
    {
        if (!isset($layer[$family])) {
            throw new \InvalidArgumentException('famiglia sconosciuta: ' . $family);
        }
        return $layer[$family];
    }
}
