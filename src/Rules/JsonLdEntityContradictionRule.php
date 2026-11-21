<?php

declare(strict_types=1);

namespace SeoLab\Rules;

use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\States;

/**
 * JSONLD_ENTITY_CONTRADICTION: due definizioni WebPage con lo stesso @id esatto, legato all'URL finale della pagina,
 * nel grafo predefinito con contesto Schema.org, di cui una dichiara l'URL della pagina e l'altra un URL scalare
 * diverso sulla stessa origine. Non dipende dall'atteso Yoast: la contraddizione osservativa resta anche senza atteso.
 * Riferimenti, URL ambigue, contesti non equivalenti o analisi incompleta sono inconclusivi per il gruppo interessato.
 */
final class JsonLdEntityContradictionRule extends AbstractRule
{
    public function id(): string
    {
        return 'JSONLD_ENTITY_CONTRADICTION';
    }

    public function family(): string
    {
        return 'jsonld';
    }

    public function evaluate(ExpectedSnapshot $expected, ObservedSnapshot $observed): RuleOutcome
    {
        $state = $this->gate($expected, $observed, 'jsonld', false);
        if ($state !== null) {
            return $this->plain($state);
        }
        $analysis = $observed->jsonLdAnalysis();
        $pageUrl = CanonicalTargets::normalize($observed->observedUrl());
        // Inventario incompleto: la coppia non si cerca neppure, perche' una definizione mancante puo' cambiare il gruppo.
        if ($analysis === null || $pageUrl === null || !$analysis['inventory_complete']) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }

        $nodes = $observed->observedValue('jsonld')->value() ?? [];
        $groups = [];
        foreach ($nodes as $index => $node) {
            if ($node['id'] === null || !in_array('WebPage', $node['types'], true) || !self::linked($node['id'], $pageUrl)) {
                continue;
            }
            $groups[$node['id']][] = $index;
        }

        $uncertain = false;
        foreach ($groups as $indexes) {
            $members = [];
            foreach ($indexes as $index) {
                $members[] = ['index' => $index, 'node' => $nodes[$index]];
            }
            // Contesto non equivalente o URL ambigua nel gruppo: il confronto del gruppo e' inconcludente.
            if (self::uncertainGroup($members)) {
                $uncertain = true;
                continue;
            }
            $pair = self::contradiction($members, $pageUrl);
            if ($pair !== null) {
                return $this->finding($expected, $pair);
            }
        }

        if ($uncertain) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        return $this->plain(States::ANOMALY_NONE);
    }

    /**
     * @param array<int, array{index: int, node: array<string, mixed>}> $members
     */
    private static function uncertainGroup(array $members): bool
    {
        foreach ($members as $member) {
            if (($member['node']['profile'] ?? false) !== true || ($member['node']['url_state'] ?? null) === 'ambiguous') {
                return true;
            }
        }
        return false;
    }

    /**
     * Coppia in ordine di documento: una definizione con URL uguale alla pagina e una con URL diversa, stessa origine.
     *
     * @param array<int, array{index: int, node: array<string, mixed>}> $members
     * @return array{0: array{index: int, node: array<string, mixed>}, 1: array{index: int, node: array<string, mixed>}}|null
     */
    private static function contradiction(array $members, string $pageUrl): ?array
    {
        foreach ($members as $self) {
            if (($self['node']['profile'] ?? false) !== true || ($self['node']['url_state'] ?? null) !== 'scalar') {
                continue;
            }
            if (CanonicalTargets::normalize($self['node']['url']) !== $pageUrl) {
                continue;
            }
            foreach ($members as $other) {
                if ($other['index'] === $self['index'] || ($other['node']['profile'] ?? false) !== true || ($other['node']['url_state'] ?? null) !== 'scalar') {
                    continue;
                }
                $otherUrl = CanonicalTargets::normalize($other['node']['url']);
                if ($otherUrl === null || $otherUrl === $pageUrl || self::origin($otherUrl) !== self::origin($pageUrl)) {
                    continue;
                }
                return $self['index'] < $other['index'] ? [$self, $other] : [$other, $self];
            }
        }
        return null;
    }

    /**
     * @param array{0: array{index: int, node: array<string, mixed>}, 1: array{index: int, node: array<string, mixed>}} $pair
     */
    private function finding(ExpectedSnapshot $expected, array $pair): RuleOutcome
    {
        $first = $pair[0]['node'];
        $second = $pair[1]['node'];
        $summary = $this->expectedSummary($expected, 'canonical');
        $expectedFinding = $summary === [] ? [] : ['property' => 'url'] + $summary;
        return $this->confirmed(
            States::SEVERITY_WARNING,
            $expectedFinding,
            ['node_id' => $first['id'], 'values' => [$first['url'], $second['url']]],
            [
                ['kind' => 'jsonld', 'locator' => $first['locator'] . '/url', 'excerpt' => CanonicalTargets::excerpt($first['url'])],
                ['kind' => 'jsonld', 'locator' => $second['locator'] . '/url', 'excerpt' => CanonicalTargets::excerpt($second['url'])],
            ],
            'Verificare quale grafo JSON-LD emette la URL alternativa per lo stesso @id',
            'Ripetere la scansione e confrontare la proprieta\' url del nodo WebPage'
        );
    }

    /**
     * L'ID collega la pagina se coincide con il suo URL finale, anche con frammento (il frammento e' rimosso solo qui).
     */
    private static function linked(string $id, string $pageUrl): bool
    {
        $hash = strpos($id, '#');
        $base = $hash === false ? $id : substr($id, 0, $hash);
        return CanonicalTargets::normalize($base) === $pageUrl;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '');
        return isset($parts['port']) ? $origin . ':' . $parts['port'] : $origin;
    }
}
