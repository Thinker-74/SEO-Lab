<?php

declare(strict_types=1);

namespace SeoLab\Rules;

use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\States;

/**
 * CANONICAL_MULTIPLE_DISTINCT: due o piu' target canonical distinti tra head HTML e header Link, indipendentemente
 * da Yoast. I target fuori head sono solo contesto e non entrano nel conteggio; i duplicati identici non sono conflitto.
 */
final class CanonicalMultipleDistinctRule extends AbstractRule
{
    public function id(): string
    {
        return 'CANONICAL_MULTIPLE_DISTINCT';
    }

    public function family(): string
    {
        return 'canonical';
    }

    public function evaluate(ExpectedSnapshot $expected, ObservedSnapshot $observed): RuleOutcome
    {
        $state = $this->gate($expected, $observed, 'canonical', false);
        if ($state !== null) {
            return $this->plain($state);
        }
        $items = CanonicalTargets::items($observed);
        if ($items === null) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }
        $targets = CanonicalTargets::candidates($items);
        if (count($targets) < 2) {
            return $this->plain(States::ANOMALY_NONE);
        }
        $urls = [];
        $evidence = [];
        foreach ($targets as $target) {
            $urls[] = $target['url'];
            $evidence[] = CanonicalTargets::evidence($target);
        }
        return $this->confirmed(
            States::SEVERITY_WARNING,
            $this->expectedSummary($expected, 'canonical'),
            ['values' => $urls],
            $evidence,
            'Isolare il componente che emette il canonical aggiuntivo (tag nell\'head o header Link) in staging',
            'Ripetere la scansione e confrontare i canonical distinti osservati'
        );
    }
}
