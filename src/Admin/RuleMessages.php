<?php

declare(strict_types=1);

namespace SeoLab\Admin;

/**
 * Testi umani per regola P0 e per stati, in dominio "seo-lab". Le stringhe sono in inglese (msgid) e il catalogo
 * it_IT le traduce. Costruite a richiesta: nessuna chiamata a __() in costanti o proprieta' statiche.
 */
final class RuleMessages
{
    /**
     * @return array{title: string, help: string}|array{}
     */
    public static function forRule(string $ruleId): array
    {
        switch ($ruleId) {
            case 'CANONICAL_MULTIPLE_DISTINCT':
                return [
                    'title' => __('More than one distinct canonical URL', 'seo-lab'),
                    'help' => __('The page declares more than one canonical target. Check the theme, the SEO plugins and the content for duplicate canonical tags.', 'seo-lab'),
                ];
            case 'CANONICAL_EXPECTED_MISMATCH':
                return [
                    'title' => __('Canonical differs from the expected value', 'seo-lab'),
                    'help' => __('The observed canonical differs from the value Yoast resolves for this page. Clear the caches, repeat the scan and compare with the output of the theme or other plugins.', 'seo-lab'),
                ];
            case 'CANONICAL_MISSING_WHEN_EXPECTED':
                return [
                    'title' => __('Canonical missing where Yoast expects one', 'seo-lab'),
                    'help' => __('No canonical tag was observed although Yoast resolves one. Check whether a template or a plugin removes the tag in the public output.', 'seo-lab'),
                ];
            case 'ROBOTS_EFFECTIVE_MISMATCH':
                return [
                    'title' => __('Effective robots directives differ from the expected value', 'seo-lab'),
                    'help' => __('The robots directives observed in the meta tag or the HTTP header differ from the value Yoast resolves. Check the headers set by the server and other plugins.', 'seo-lab'),
                ];
            case 'ROBOTS_UNEXPECTED_NOINDEX':
                return [
                    'title' => __('Unexpected noindex', 'seo-lab'),
                    'help' => __('The page is observed as noindex although Yoast does not expect it. Check the indexing settings of the content and any header-level directive.', 'seo-lab'),
                ];
            case 'JSONLD_PARSE_ERROR':
                return [
                    'title' => __('JSON-LD block cannot be parsed', 'seo-lab'),
                    'help' => __('A JSON-LD block in the public output is not valid JSON. Check the templates and plugins that print structured data.', 'seo-lab'),
                ];
            case 'JSONLD_ENTITY_CONTRADICTION':
                return [
                    'title' => __('JSON-LD entity contradicts the expected one', 'seo-lab'),
                    'help' => __('An entity in the observed JSON-LD contradicts the entity Yoast expects for this page. Compare the @type and @id values in the public output.', 'seo-lab'),
                ];
            case 'JSONLD_EXPECTED_NODE_MISSING':
                return [
                    'title' => __('Expected JSON-LD node missing', 'seo-lab'),
                    'help' => __('The public output lacks a JSON-LD node that Yoast expects. Check whether the schema output is disabled or replaced by another plugin.', 'seo-lab'),
                ];
            default:
                return [];
        }
    }
}
