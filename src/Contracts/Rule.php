<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Regola versionata. Restituisce sempre un RuleOutcome: il Finding e' associato solo a un'anomalia confermata.
 */
interface Rule
{
    public function id(): string;

    public function version(): string;

    public function family(): string;

    public function evaluate(ExpectedSnapshot $expected, ObservedSnapshot $observed): RuleOutcome;
}
