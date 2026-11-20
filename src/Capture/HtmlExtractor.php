<?php

declare(strict_types=1);

namespace SeoLab\Capture;

/**
 * Estrazione di canonical, robots e presenza di JSON-LD da un corpo HTML gia' limitato in dimensione.
 * Il DOM e' isolato: nessun caricamento di risorse esterne (LIBXML_NONET, nessuna opzione di entita'),
 * stato libxml ripristinato. Il contenuto JSON-LD non viene analizzato qui (fase S3).
 */
final class HtmlExtractor
{
    private const ABSOLUTE_HTTP = '#^https?://[^\s]+\z#';

    private const KNOWN_DIRECTIVES = [
        'all', 'none', 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'notranslate',
        'unavailable_after', 'max-snippet', 'max-image-preview', 'max-video-preview', 'noodp', 'noydir',
        'indexifembedded', 'nocache',
    ];

    /**
     * @param string[] $xRobotsTag valori grezzi dell'header X-Robots-Tag
     * @return array<string, mixed> chiave 'error' con motivo stabile, oppure 'values' e 'positions'
     */
    public static function extract(string $body, ?string $httpCharset, string $baseUrl, bool $domAvailable, array $xRobotsTag): array
    {
        if (!$domAvailable || !class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            return ['error' => 'parser_unavailable'];
        }
        if ($httpCharset !== null && !self::isUtf8Label($httpCharset)) {
            return ['error' => 'unsupported_response'];
        }

        $raw = self::load($body);
        if ($raw === null) {
            return ['error' => 'invalid_html'];
        }
        foreach (self::declaredCharsets(new \DOMXPath($raw)) as $label) {
            if (!self::isUtf8Label($label)) {
                return ['error' => 'unsupported_response'];
            }
        }

        if (!preg_match('//u', $body)) {
            return ['error' => 'invalid_html'];
        }
        $copy = strncmp($body, "\xEF\xBB\xBF", 3) === 0 ? substr($body, 3) : $body;
        $doc = self::load('<?xml encoding="UTF-8"?>' . $copy);
        if ($doc === null) {
            return ['error' => 'invalid_html'];
        }

        return self::readDocument(new \DOMXPath($doc), $baseUrl, $xRobotsTag);
    }

    /**
     * Carica l'HTML con libxml isolato: nessun accesso di rete, errori raccolti internamente, stato ripristinato.
     */
    private static function load(string $html): ?\DOMDocument
    {
        if ($html === '') {
            return null;
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $loaded ? $doc : null;
    }

    /**
     * Dichiarazioni di charset effettive nel DOM (meta charset e http-equiv content-type), esclusi i template.
     *
     * @return string[]
     */
    private static function declaredCharsets(\DOMXPath $xp): array
    {
        $labels = [];
        $charsetNodes = $xp->query('//meta[not(ancestor::template)][@charset]');
        foreach ($charsetNodes as $node) {
            $labels[] = trim($node->getAttribute('charset'));
        }
        $equivNodes = $xp->query("//meta[not(ancestor::template)][translate(@http-equiv, 'CONTENT-TYPE', 'content-type')='content-type']");
        foreach ($equivNodes as $node) {
            if (preg_match('/charset\s*=\s*"?([^";\s]+)/i', $node->getAttribute('content'), $m)) {
                $labels[] = $m[1];
            }
        }
        return $labels;
    }

    private static function isUtf8Label(string $label): bool
    {
        $normalized = strtolower(trim($label, " \t\r\n\"'"));
        return $normalized === 'utf-8' || $normalized === 'utf8';
    }

    /**
     * @param string[] $xRobotsTag
     * @return array<string, mixed>
     */
    private static function readDocument(\DOMXPath $xp, string $baseUrl, array $xRobotsTag): array
    {
        // Base: primo base[href] del DOM fuori dai template, risolto contro l'URL finale. Non attiva richieste.
        $documentBase = $baseUrl;
        $baseUnusable = false;
        $baseNodes = $xp->query('//base[not(ancestor::template)][@href]');
        if ($baseNodes->length > 0) {
            $baseHref = trim($baseNodes->item(0)->getAttribute('href'), " \t\r\n\f");
            $resolvedBase = UrlTools::resolve($baseUrl, $baseHref);
            if ($resolvedBase !== null && preg_match(self::ABSOLUTE_HTTP, $resolvedBase)) {
                $documentBase = $resolvedBase;
            } else {
                $baseUnusable = true;
            }
        }

        // Canonical: ogni link con token rel "canonical" fuori dai template.
        $canonical = [];
        $canonicalPositions = [];
        $canonicalAmbiguous = false;
        $links = $xp->query("//link[not(ancestor::template)][contains(concat(' ', translate(normalize-space(@rel), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), ' '), ' canonical ')]");
        foreach ($links as $node) {
            $href = $node->hasAttribute('href') ? trim($node->getAttribute('href'), " \t\r\n\f") : '';
            if (preg_match('#^(https?)(:.*)\z#i', $href, $m)) {
                $href = strtolower($m[1]) . $m[2];
            }
            // Riferimento senza schema: dipende dalla base, che se non interpretabile rende ambiguo il canonical.
            $dependsOnBase = preg_match('#^[a-z][a-z0-9+.\-]*:#i', $href) !== 1;
            if ($dependsOnBase && $baseUnusable) {
                $canonicalAmbiguous = true;
                continue;
            }
            $resolved = ($href === '' || preg_match('/\s/', $href)) ? null : UrlTools::resolve($documentBase, $href);
            if ($resolved === null || !preg_match(self::ABSOLUTE_HTTP, $resolved)) {
                $canonicalAmbiguous = true;
                continue;
            }
            $canonical[] = $resolved;
            $canonicalPositions[] = self::position($xp, $node);
        }

        // Robots da meta: name robots (bot all) o nomi di bot (contengono "bot").
        $robots = [];
        $robotsPositions = [];
        $robotsAmbiguous = false;
        $metas = $xp->query('//meta[not(ancestor::template)][@name]');
        foreach ($metas as $node) {
            $name = strtolower(trim($node->getAttribute('name')));
            if ($name !== 'robots' && strpos($name, 'bot') === false) {
                continue;
            }
            $bot = $name === 'robots' ? 'all' : $name;
            $content = $node->hasAttribute('content') ? $node->getAttribute('content') : '';
            $tokens = array_values(array_filter(array_map('trim', explode(',', strtolower($content))), static function ($t): bool {
                return $t !== '';
            }));
            if ($tokens === []) {
                $robotsAmbiguous = true;
                continue;
            }
            foreach ($tokens as $token) {
                $robots[] = ['source' => 'meta', 'bot' => $bot, 'directive' => $token];
                $robotsPositions[] = self::position($xp, $node);
            }
        }

        // Robots da header X-Robots-Tag: scope bot con prefisso "nome:", altrimenti all.
        foreach (self::parseXRobots($xRobotsTag) as $item) {
            $robots[] = $item;
            $robotsPositions[] = 'http_header';
        }

        // JSON-LD: solo presenza. Il contenuto e' differito a S3, quindi con script l'osservazione e' unobservable.
        // Candidato riconosciuto dall'essenza del MIME, prima di ';', senza distinguere maiuscole e spazi.
        $jsonLdCount = 0;
        $scripts = $xp->query('//script[not(ancestor::template)]');
        foreach ($scripts as $node) {
            $essence = strtolower(trim(explode(';', $node->getAttribute('type'))[0]));
            if ($essence === 'application/ld+json') {
                $jsonLdCount++;
            }
        }

        $canonicalFamily = self::family($canonical, $canonicalAmbiguous);
        $robotsFamily = self::family($robots, $robotsAmbiguous);
        $jsonLdFamily = $jsonLdCount > 0
            ? ['availability' => 'unobservable', 'value' => null]
            : ['availability' => 'absent', 'value' => []];

        return [
            'values' => ['canonical' => $canonicalFamily, 'robots' => $robotsFamily, 'jsonld' => $jsonLdFamily],
            'positions' => [
                'canonical' => $canonicalFamily['availability'] === 'present' ? $canonicalPositions : [],
                'robots' => $robotsFamily['availability'] === 'present' ? $robotsPositions : [],
            ],
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<string, mixed>
     */
    private static function family(array $items, bool $ambiguous): array
    {
        // Una dichiarazione ambigua rende l'intera famiglia non valutabile, anche con altre dichiarazioni valide.
        if ($ambiguous) {
            return ['availability' => 'unobservable', 'value' => null];
        }
        if ($items !== []) {
            return ['availability' => 'present', 'value' => $items];
        }
        return ['availability' => 'absent', 'value' => []];
    }

    /**
     * @param string[] $values
     * @return array<int, array<string, string>>
     */
    private static function parseXRobots(array $values): array
    {
        $items = [];
        foreach ($values as $value) {
            $bot = 'all';
            foreach (explode(',', strtolower($value)) as $token) {
                $token = trim($token);
                if ($token === '') {
                    continue;
                }
                if (preg_match('/^([a-z0-9_.-]+)\s*:\s*(.*)$/', $token, $m) && !in_array($m[1], self::KNOWN_DIRECTIVES, true)) {
                    $bot = $m[1];
                    $token = trim($m[2]);
                    if ($token === '') {
                        continue;
                    }
                }
                $items[] = ['source' => 'header', 'bot' => $bot, 'directive' => $token];
            }
        }
        return $items;
    }

    private static function position(\DOMXPath $xp, \DOMNode $node): string
    {
        return $xp->evaluate('count(ancestor::head)', $node) > 0 ? 'head' : 'outside_head';
    }

    private function __construct()
    {
    }
}
