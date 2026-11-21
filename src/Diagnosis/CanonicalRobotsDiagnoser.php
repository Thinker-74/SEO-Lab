<?php

declare(strict_types=1);

namespace SeoLab\Diagnosis;

use SeoLab\Contracts\ExpectedProvider;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Rules\CanonicalExpectedMismatchRule;
use SeoLab\Rules\CanonicalMissingWhenExpectedRule;
use SeoLab\Rules\CanonicalMultipleDistinctRule;
use SeoLab\Rules\RobotsEffectiveMismatchRule;
use SeoLab\Rules\RobotsUnexpectedNoindexRule;

/**
 * Diagnosi canonical e robots di una pagina: risolve l'atteso una sola volta dal provider iniettato e restituisce
 * i cinque esiti delle regole P0 in ordine stabile. La capture e' fornita dal chiamante (captureForPost).
 */
final class CanonicalRobotsDiagnoser
{
    /** @var ExpectedProvider */
    private $provider;

    public function __construct(ExpectedProvider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * @return RuleOutcome[]
     */
    public function diagnose(int $postId, ObservedSnapshot $observed): array
    {
        $expected = $this->provider->forPost($postId);
        $rules = [
            new CanonicalMultipleDistinctRule(),
            new CanonicalExpectedMismatchRule(),
            new CanonicalMissingWhenExpectedRule(),
            new RobotsEffectiveMismatchRule(),
            new RobotsUnexpectedNoindexRule(),
        ];
        $outcomes = [];
        foreach ($rules as $rule) {
            $outcomes[] = $rule->evaluate($expected, $observed);
        }
        return $outcomes;
    }
}
