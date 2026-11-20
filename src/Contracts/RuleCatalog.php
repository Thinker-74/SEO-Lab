<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Catalogo delle regole P0 per famiglia (specifica par. 6). Gli ID sono codici interni in inglese.
 */
final class RuleCatalog
{
    public const RULES = [
        'canonical' => [
            'CANONICAL_MULTIPLE_DISTINCT',
            'CANONICAL_EXPECTED_MISMATCH',
            'CANONICAL_MISSING_WHEN_EXPECTED',
        ],
        'robots' => [
            'ROBOTS_EFFECTIVE_MISMATCH',
            'ROBOTS_UNEXPECTED_NOINDEX',
        ],
        'jsonld' => [
            'JSONLD_PARSE_ERROR',
            'JSONLD_ENTITY_CONTRADICTION',
            'JSONLD_EXPECTED_NODE_MISSING',
        ],
    ];

    /**
     * @return string[]
     */
    public static function families(): array
    {
        return array_keys(self::RULES);
    }

    /**
     * @return string[]
     */
    public static function rulesOf(string $family): array
    {
        if (!isset(self::RULES[$family])) {
            throw new \InvalidArgumentException('famiglia sconosciuta: ' . $family);
        }
        return self::RULES[$family];
    }

    public static function familyOf(string $ruleId): ?string
    {
        foreach (self::RULES as $family => $ids) {
            if (in_array($ruleId, $ids, true)) {
                return (string) $family;
            }
        }
        return null;
    }

    private function __construct()
    {
    }
}
