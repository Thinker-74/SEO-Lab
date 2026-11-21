<?php

declare(strict_types=1);

namespace SeoLab\Rules;

use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\States;

/**
 * CANONICAL_EXPECTED_MISMATCH: il target risolto da Yoast differisce dall'unico canonical candidato osservato.
 * Non confrontabile (piu' candidati, target fuori head discordante, noindex pertinente, atteso vuoto o non normalizzabile,
 * sola differenza di codifica percent) -> inconclusive.
 */
final class CanonicalExpectedMismatchRule extends AbstractRule
{
    public function id(): string
    {
        return 'CANONICAL_EXPECTED_MISMATCH';
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
        $candidates = CanonicalTargets::candidates($items);
        if ($candidates === []) {
            return $this->plain(CanonicalTargets::context($items) === [] ? States::ANOMALY_NONE : States::ANOMALY_INCONCLUSIVE);
        }
        if (count($candidates) > 1) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        $target = $candidates[0];
        foreach (CanonicalTargets::context($items) as $item) {
            if ($item['norm'] !== $target['norm']) {
                return $this->plain(States::ANOMALY_INCONCLUSIVE);
            }
        }
        $resolved = (string) $expected->resolved('canonical')->value();
        $resolvedNorm = CanonicalTargets::normalize($resolved);
        if ($resolvedNorm === null || RobotsSemantics::noindexState($observed) !== 'no') {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        if ($target['norm'] === $resolvedNorm) {
            return $this->plain(States::ANOMALY_NONE);
        }
        // Differenza solo di codifica percent: non e' una prova di target diverso.
        if (rawurldecode($target['norm']) === rawurldecode($resolvedNorm)) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        return $this->confirmed(
            States::SEVERITY_WARNING,
            $this->expectedSummary($expected, 'canonical'),
            ['values' => [$target['url']]],
            [CanonicalTargets::evidence($target)],
            'Verificare se il canonical osservato deriva da una regola contestuale della pagina (redirect, lingua, filtro o tema)',
            'Ripetere la scansione e confrontare il canonical osservato con il valore risolto da Yoast'
        );
    }
}
