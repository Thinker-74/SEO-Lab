<?php

declare(strict_types=1);

namespace SeoLab\Diagnosis;

use SeoLab\Contracts\ExpectedProvider;
use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Rules\JsonLdEntityContradictionRule;
use SeoLab\Rules\JsonLdExpectedNodeMissingRule;
use SeoLab\Rules\JsonLdParseErrorRule;

/**
 * Diagnosi JSON-LD di una pagina (S3): risolve l'atteso una sola volta dal provider iniettato e restituisce i tre
 * esiti delle regole JSONLD_* in ordine stabile. La capture e' fornita dal chiamante.
 */
final class JsonLdDiagnoser
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
        return $this->diagnoseResolved($this->provider->forPost($postId), $observed);
    }

    /**
     * Come diagnose(), con l'atteso gia' risolto dal chiamante (una sola risoluzione per scansione).
     *
     * @return RuleOutcome[]
     */
    public function diagnoseResolved(ExpectedSnapshot $expected, ObservedSnapshot $observed): array
    {
        $rules = [
            new JsonLdParseErrorRule(),
            new JsonLdEntityContradictionRule(),
            new JsonLdExpectedNodeMissingRule(),
        ];
        $outcomes = [];
        foreach ($rules as $rule) {
            $outcomes[] = $rule->evaluate($expected, $observed);
        }
        return $outcomes;
    }
}
