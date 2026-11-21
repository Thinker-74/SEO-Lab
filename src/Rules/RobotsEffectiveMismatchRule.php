<?php

declare(strict_types=1);

namespace SeoLab\Rules;

use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\States;

/**
 * ROBOTS_EFFECTIVE_MISMATCH: differenza verificabile fra le direttive robots risolte da Yoast e le restrizioni effettive
 * per Googlebot nella risposta (meta robots, googlebot e X-Robots-Tag, anche fuori head). Sintassi o direttive
 * contestuali non confrontabili -> inconclusive. Equivalenze (all/index,follow, none/noindex,nofollow,
 * nosnippet/max-snippet:0) -> nessuna anomalia.
 */
final class RobotsEffectiveMismatchRule extends AbstractRule
{
    public function id(): string
    {
        return 'ROBOTS_EFFECTIVE_MISMATCH';
    }

    public function family(): string
    {
        return 'robots';
    }

    public function evaluate(ExpectedSnapshot $expected, ObservedSnapshot $observed): RuleOutcome
    {
        $state = $this->gate($expected, $observed, 'robots', true);
        if ($state !== null) {
            return $this->plain($state);
        }
        $obs = RobotsSemantics::observed($observed);
        $exp = RobotsSemantics::expected($expected);
        if ($obs === null || $exp === null) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        $effectiveObserved = RobotsSemantics::effective($obs['directives']);
        $effectiveExpected = RobotsSemantics::effective($exp['directives']);
        if (!$effectiveObserved['valid'] || !$effectiveExpected['valid']
            || $effectiveObserved['contextual'] || $effectiveExpected['contextual']) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        $differences = RobotsSemantics::differences($effectiveExpected['dims'], $effectiveObserved['dims']);
        if ($differences === []) {
            return $this->plain(States::ANOMALY_NONE);
        }
        $evidence = RobotsSemantics::evidence($obs['items'], $differences);
        if ($evidence === []) {
            // Differenza per assenza di restrizione osservata: la prova e' il documento completo.
            $evidence = [[
                'kind' => 'html',
                'locator' => 'snapshot.observed_values.robots (documento HTML completo, capture ok)',
                'excerpt' => 'robots osservati in ambito: []',
            ]];
        }
        return $this->confirmed(
            States::SEVERITY_ERROR,
            $this->expectedSummary($expected, 'robots'),
            [
                'effect_for_googlebot' => $effectiveObserved['dims']['index'] === 'noindex' ? 'noindex' : 'index',
                'directives' => $obs['directives'],
            ],
            $evidence,
            'Isolare il componente che emette la direttiva robots osservata (meta, googlebot o X-Robots-Tag) in staging',
            'Ripetere la scansione e confrontare le direttive effettive per Googlebot con il valore risolto da Yoast'
        );
    }
}
