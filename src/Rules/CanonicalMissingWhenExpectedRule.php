<?php

declare(strict_types=1);

namespace SeoLab\Rules;

use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\States;

/**
 * CANONICAL_MISSING_WHEN_EXPECTED: assenza dimostrata di canonical (documento completo, head e header Link) quando
 * Yoast risolve un target non vuoto e affidabile, senza noindex pertinente e con contesto confrontabile.
 * Con almeno un candidato, oppure con atteso vuoto o noindex intenzionale, nessuna anomalia.
 */
final class CanonicalMissingWhenExpectedRule extends AbstractRule
{
    public function id(): string
    {
        return 'CANONICAL_MISSING_WHEN_EXPECTED';
    }

    public function family(): string
    {
        return 'canonical';
    }

    public function evaluate(ExpectedSnapshot $expected, ObservedSnapshot $observed): RuleOutcome
    {
        $state = $this->gate($expected, $observed, 'canonical', true);
        if ($state !== null) {
            return $this->plain($state);
        }
        $items = CanonicalTargets::items($observed);
        if ($items === null) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        if (CanonicalTargets::candidates($items) !== []) {
            return $this->plain(States::ANOMALY_NONE);
        }
        if (CanonicalTargets::context($items) !== []) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        $resolved = (string) $expected->resolved('canonical')->value();
        if ($resolved === '') {
            return $this->plain(States::ANOMALY_NONE);
        }
        $noindex = RobotsSemantics::noindexState($observed);
        if ($noindex === 'yes') {
            return $this->plain(States::ANOMALY_NONE);
        }
        if ($noindex === 'unknown' || CanonicalTargets::normalize($resolved) === null) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        return $this->confirmed(
            States::SEVERITY_WARNING,
            $this->expectedSummary($expected, 'canonical'),
            ['values' => []],
            [[
                'kind' => 'html',
                'locator' => 'snapshot.observed_values.canonical (documento HTML completo, capture ok)',
                'excerpt' => 'canonical osservati: []',
            ]],
            'Verificare nel tema e nei plugin di SEO l\'emissione del canonical per questa pagina in staging',
            'Ripetere la scansione e verificare la presenza del canonical nel documento completo e negli header Link'
        );
    }
}
