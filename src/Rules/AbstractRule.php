<?php

declare(strict_types=1);

namespace SeoLab\Rules;

use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\Finding;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\Rule;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\States;

/**
 * Base delle regole P0: versione 1.0.0, esiti non positivi con causa unknown e Finding solo per anomalie confermate.
 */
abstract class AbstractRule implements Rule
{
    public function version(): string
    {
        return '1.0.0';
    }

    protected function plain(string $state): RuleOutcome
    {
        return new RuleOutcome($this->id(), $this->version(), $state, States::CAUSE_UNKNOWN, null);
    }

    /**
     * @param array<string, mixed>             $expected
     * @param array<string, mixed>             $observed
     * @param array<int, array<string, mixed>> $evidence
     */
    protected function confirmed(string $severity, array $expected, array $observed, array $evidence, string $nextTest, string $verification): RuleOutcome
    {
        $finding = new Finding([
            'rule_id' => $this->id(),
            'rule_version' => $this->version(),
            'state' => States::ANOMALY_CONFIRMED,
            'severity' => $severity,
            'expected' => $expected,
            'observed' => $observed,
            'evidence' => $evidence,
            'cause' => ['state' => States::CAUSE_UNKNOWN, 'candidates' => [], 'proof' => []],
            'next_test' => $nextTest,
            'verification_method' => $verification,
        ]);
        return new RuleOutcome($this->id(), $this->version(), States::ANOMALY_CONFIRMED, States::CAUSE_UNKNOWN, $finding);
    }

    /**
     * Guardie comuni: capture, famiglia osservata non valutabile e, se richiesto, confronto con l'atteso Yoast.
     */
    protected function gate(ExpectedSnapshot $expected, ObservedSnapshot $observed, string $family, bool $comparison): ?string
    {
        $state = Gate::capture($observed);
        if ($state !== null) {
            return $state;
        }
        if ($observed->observedValue($family)->availability() === 'unobservable') {
            return States::ANOMALY_INCONCLUSIVE;
        }
        return $comparison ? Gate::comparison($observed, $expected, $family) : null;
    }

    /**
     * Valore risolto da Yoast per la sezione "expected" del Finding, vuoto se non disponibile.
     *
     * @return array<string, mixed>
     */
    protected function expectedSummary(ExpectedSnapshot $expected, string $family): array
    {
        $value = $expected->resolved($family);
        if ($value->availability() !== States::AVAILABLE) {
            return [];
        }
        return ['value' => $value->value(), 'source' => $value->source()];
    }
}
