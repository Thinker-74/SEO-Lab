<?php

declare(strict_types=1);

namespace SeoLab\Rules;

use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\States;

/**
 * JSONLD_PARSE_ERROR: errore di sintassi JSON provato su un blocco ricevuto integralmente e dentro budget
 * (confirmed_anomaly). Budget esauriti, blocchi non interpretabili o analisi assente: inconclusive. Tutti i blocchi
 * sintatticamente validi o assenza di script dimostrata: no_anomaly, anche se il profilo semantico non e' valutabile.
 */
final class JsonLdParseErrorRule extends AbstractRule
{
    public function id(): string
    {
        return 'JSONLD_PARSE_ERROR';
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
        if ($analysis === null) {
            return $this->plain(States::ANOMALY_INCONCLUSIVE);
        }

        $errors = [];
        $evidence = [];
        foreach ($analysis['errors'] as $error) {
            if ($error['code'] !== 'JSON_SYNTAX') {
                continue;
            }
            $errors[] = ['code' => $error['code'], 'locator' => $error['locator']];
            $evidence[] = ['kind' => 'jsonld', 'locator' => $error['locator'], 'excerpt' => $error['code']];
        }
        if ($evidence !== []) {
            return $this->confirmed(
                States::SEVERITY_WARNING,
                [],
                ['errors' => $errors],
                $evidence,
                'Verificare nel tema e nei plugin di SEO il blocco JSON-LD indicato dal locator e correggerne la sintassi',
                'Ripetere la scansione e verificare che ogni blocco application/ld+json sia JSON valido'
            );
        }
        return $this->plain($analysis['syntax_complete'] ? States::ANOMALY_NONE : States::ANOMALY_INCONCLUSIVE);
    }
}
