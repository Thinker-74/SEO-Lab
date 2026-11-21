<?php

declare(strict_types=1);

namespace SeoLab\Rules;

use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\States;

/**
 * ROBOTS_UNEXPECTED_NOINDEX: noindex effettivo per Googlebot dove Yoast si aspetta una pagina indicizzabile, con
 * prova puntuale (meta, googlebot o X-Robots-Tag, anche fuori head). Non copre robots.txt ne' altri sistemi di controllo
 * dell'indicizzazione. Noindex atteso o bot fuori scope: nessuna anomalia.
 */
final class RobotsUnexpectedNoindexRule extends AbstractRule
{
    public function id(): string
    {
        return 'ROBOTS_UNEXPECTED_NOINDEX';
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
        if (!$effectiveObserved['valid'] || !$effectiveExpected['valid'] || $effectiveExpected['contextual']) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        if ($effectiveObserved['dims']['index'] !== 'noindex' || $effectiveExpected['dims']['index'] === 'noindex') {
            return $this->plain(States::ANOMALY_NONE);
        }
        $evidence = RobotsSemantics::evidence($obs['items'], ['index']);
        if ($evidence === []) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        return $this->confirmed(
            States::SEVERITY_ERROR,
            $this->expectedSummary($expected, 'robots'),
            ['effect_for_googlebot' => 'noindex', 'directives' => $obs['directives']],
            $evidence,
            'Verificare la sorgente della direttiva noindex (meta, googlebot o X-Robots-Tag) e le impostazioni di visibilita del sito; robots.txt e altri sistemi non sono coperti dal test',
            'Ripetere la scansione e confrontare la direttiva noindex con il valore risolto da Yoast'
        );
    }
}
