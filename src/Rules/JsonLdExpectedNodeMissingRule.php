<?php

declare(strict_types=1);

namespace SeoLab\Rules;

use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\States;
use SeoLab\Contracts\ValueCopy;

/**
 * JSONLD_EXPECTED_NODE_MISSING: ID atteso da Yoast senza alcuna definizione nel JSON-LD della pagina, con capture
 * completa, inventario e profilo completi. Una definizione nel profilo per ogni ID: nessuna anomalia. Solo referenza,
 * definizione fuori profilo, analisi incompleta o atteso non valutabile: inconclusive.
 */
final class JsonLdExpectedNodeMissingRule extends AbstractRule
{
    private const ABSOLUTE_HTTP = '#^https?://[^\s]+\z#';

    public function id(): string
    {
        return 'JSONLD_EXPECTED_NODE_MISSING';
    }

    public function family(): string
    {
        return 'jsonld';
    }

    public function evaluate(ExpectedSnapshot $expected, ObservedSnapshot $observed): RuleOutcome
    {
        $state = $this->gate($expected, $observed, 'jsonld', true);
        if ($state !== null) {
            return $this->plain($state);
        }
        $analysis = $observed->jsonLdAnalysis();
        if ($analysis === null) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        $value = $expected->resolved('jsonld')->value();
        $ids = is_array($value) ? ($value['expected_node_ids'] ?? null) : null;
        if (!is_array($ids) || !ValueCopy::isList($ids)) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        if ($ids === []) {
            return $this->plain(States::ANOMALY_NONE);
        }

        $nodes = $observed->observedValue('jsonld')->value() ?? [];
        $missing = [];
        $uncertain = false;
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match(self::ABSOLUTE_HTTP, $id) !== 1) {
                return $this->plain(States::ANOMALY_INCONCLUSIVE);
            }
            $defined = false;
            $outOfProfile = false;
            foreach ($nodes as $node) {
                if ($node['id'] !== $id) {
                    continue;
                }
                if (($node['profile'] ?? false) === true) {
                    $defined = true;
                } else {
                    $outOfProfile = true;
                }
            }
            if ($defined) {
                continue;
            }
            if ($outOfProfile || in_array($id, $analysis['reference_ids'], true)) {
                $uncertain = true;
                continue;
            }
            if ($analysis['inventory_complete'] && $analysis['profile_complete']) {
                $missing[] = $id;
            } else {
                $uncertain = true;
            }
        }

        if ($missing !== []) {
            return $this->confirmed(
                States::SEVERITY_WARNING,
                $this->expectedSummary($expected, 'jsonld'),
                ['missing_node_ids' => $missing],
                [[
                    'kind' => 'jsonld',
                    'locator' => 'snapshot.observed_values.jsonld (inventario completo, blocchi: ' . $analysis['blocks'] . ')',
                    'excerpt' => CanonicalTargets::excerpt('nessuna definizione per ' . implode(', ', $missing)),
                ]],
                'Verificare nel tema e nei plugin di SEO la presenza del nodo atteso nel JSON-LD della pagina',
                'Ripetere la scansione e verificare la definizione del nodo atteso nell\'inventario JSON-LD completo'
            );
        }
        return $this->plain($uncertain ? States::ANOMALY_INCONCLUSIVE : States::ANOMALY_NONE);
    }
}
