<?php

declare(strict_types=1);

namespace SeoLab\Capture;

/**
 * Analisi dei blocchi JSON-LD attivi (application/ld+json) di un documento gia' letto dal DOM (S3).
 *
 * Budget inclusivi: 32 script, 64 KiB per script, 256 KiB aggregati, 32 contenitori annidati (json_decode depth 33),
 * 256 nodi definiti e 256 riferimenti. Lo sforamento rende copertura incompleta e preserva gli errori gia' provati.
 * Il testo DOM di uno script deve essere seguito subito da un end tag script (case-insensitive) nel body grezzo:
 * altrimenti il blocco non e' interpretabile. Chiavi duplicate: vale l'ultimo valore (semantica di json_decode).
 * Nessun contenuto grezzo esce da questa classe: solo codici stabili, indici e locator.
 */
final class JsonLdExtractor
{
    private const MAX_SCRIPTS = 32;
    private const MAX_SCRIPT_BYTES = 65536;
    private const MAX_TOTAL_BYTES = 262144;
    private const MAX_DEPTH = 33;
    private const MAX_NODES = 256;
    private const MAX_ERRORS = 16;
    private const SCHEMA_CONTEXTS = ['http://schema.org', 'http://schema.org/', 'https://schema.org', 'https://schema.org/'];
    private const SYNTAX_CODES = [JSON_ERROR_STATE_MISMATCH, JSON_ERROR_CTRL_CHAR, JSON_ERROR_SYNTAX];
    private const ABSOLUTE_HTTP = '#^https?://[^\s]+\z#';
    private const TEXT_CONTAINERS = 'textarea|title|xmp|iframe|noembed|noframes';

    /**
     * @return array{value: array<string, mixed>, analysis: array<string, mixed>}
     */
    public static function analyze(string $raw, \DOMXPath $xp): array
    {
        // Testo per il browser, non blocchi attivi: contenitori di solo testo, template e noscript. Dopo un plaintext
        // attivo il documento e' testo: gli script che lo seguono in ordine documento non sono blocchi.
        $textual = 'self::textarea or self::title or self::xmp or self::iframe or self::noembed or self::noframes'
            . ' or self::plaintext or self::template or self::noscript';
        // Un solo plaintext attivo basta: gli script che lo seguono sono quelli del suo asse following (lineare).
        // Il plaintext dentro svg/math e' un elemento estraneo: il parser HTML non entra nello stato testuale.
        $after = [];
        $plain = $xp->query('(//plaintext[not(ancestor::svg) and not(ancestor::math) and not(ancestor::*[' . $textual . '])])[1]');
        if ($plain !== false && $plain->length > 0) {
            foreach ($xp->query('following::script', $plain->item(0)) as $follower) {
                // Il riferimento al wrapper resta vivo: un id di spl_object_id non puo' essere riutilizzato da un altro nodo.
                $after[spl_object_id($follower)] = $follower;
            }
        }
        $intervals = self::textualIntervals($raw);
        $scripts = [];
        foreach ($xp->query('//script[not(ancestor::*[' . $textual . '])]') as $candidate) {
            if (!isset($after[spl_object_id($candidate)])) {
                $scripts[] = $candidate;
            }
        }
        $acc = [
            'syntax_complete' => true,
            'inventory_complete' => true,
            'profile_complete' => true,
            'blocks' => 0,
            'script' => 0,
            'bytes' => 0,
            'errors' => [],
            'nodes' => [],
            'node_count' => 0,
            'reference_ids' => [],
            'nodes_budget' => false,
        ];
        $cursor = 0;
        foreach ($scripts as $node) {
            $essence = strtolower(trim(explode(';', $node->getAttribute('type'))[0]));
            if ($essence !== 'application/ld+json') {
                continue;
            }
            $acc['blocks']++;
            $acc['script'] = $acc['blocks'];
            if ($acc['script'] > self::MAX_SCRIPTS) {
                self::flag($acc, 'BUDGET_SCRIPTS', false, false);
                continue;
            }
            $text = $node->textContent;
            if (trim($text, " \t\r\n\f") === '') {
                self::flag($acc, 'JSON_EMPTY', false, false);
                continue;
            }
            $start = self::locate($raw, $text, $cursor);
            if ($start === null || self::inside($intervals, $start)) {
                self::flag($acc, 'JSON_UNMAPPED', false, false);
                continue;
            }
            if (strlen($text) > self::MAX_SCRIPT_BYTES) {
                self::flag($acc, 'BUDGET_SCRIPT_BYTES', false, false);
                continue;
            }
            if ($acc['bytes'] + strlen($text) > self::MAX_TOTAL_BYTES) {
                self::flag($acc, 'BUDGET_TOTAL_BYTES', false, false);
                continue;
            }
            $acc['bytes'] += strlen($text);

            $decoded = json_decode($text, false, self::MAX_DEPTH);
            $code = json_last_error();
            if ($code === JSON_ERROR_NONE) {
                self::walk($decoded, 'script[' . $acc['script'] . ']', null, false, true, $acc);
            } elseif (in_array($code, self::SYNTAX_CODES, true)) {
                self::flag($acc, 'JSON_SYNTAX', true, false);
            } elseif ($code === JSON_ERROR_DEPTH) {
                self::flag($acc, 'JSON_DEPTH_LIMIT', false, false);
            } else {
                self::flag($acc, 'JSON_UNINTERPRETABLE', false, false);
            }
        }

        $present = $acc['blocks'] > 0;
        return [
            'value' => ['availability' => $present ? 'present' : 'absent', 'value' => $present ? $acc['nodes'] : []],
            'analysis' => [
                'syntax_complete' => $acc['syntax_complete'],
                'inventory_complete' => $acc['inventory_complete'],
                'profile_complete' => $acc['profile_complete'],
                'blocks' => $acc['blocks'],
                'errors' => $acc['errors'],
                'reference_ids' => $acc['reference_ids'],
            ],
        ];
    }

    /**
     * Associa il testo DOM dello script ai byte del body: deve essere seguito subito da end tag script.
     * Restituisce l'offset d'inizio del match nei byte, oppure null.
     */
    private static function locate(string $raw, string $text, int &$cursor): ?int
    {
        $pos = strpos($raw, $text, $cursor);
        while ($pos !== false) {
            $after = $pos + strlen($text);
            if (strncasecmp((string) substr($raw, $after, 8), '</script', 8) === 0) {
                $cursor = $after;
                return $pos;
            }
            $pos = strpos($raw, $text, $pos + 1);
        }
        return null;
    }

    /**
     * Intervalli in byte [inizio, fine) di contenitori di solo testo che libxml puo' chiudere prima del browser: da
     * apertura a prima chiusura con nome corrispondente (insensibile al caso, separatore di tag valido). Se la chiusura
     * non e' dimostrabile, l'intervallo arriva a fine body. Scansione unica in ordine crescente.
     *
     * @return array<int, array{int, int}>
     */
    private static function textualIntervals(string $raw): array
    {
        $intervals = [];
        $offset = 0;
        $length = strlen($raw);
        while ($offset < $length
            && preg_match('~<(' . self::TEXT_CONTAINERS . ')(?=[\s/>])~i', $raw, $open, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $open[0][1];
            $bodyStart = $start + strlen($open[0][0]);
            $end = $length;
            if (preg_match('~</' . preg_quote($open[1][0], '~') . '(?=[\s/>])~i', $raw, $close, PREG_OFFSET_CAPTURE, $bodyStart) === 1) {
                $end = $close[0][1];
            }
            $intervals[] = [$start, $end];
            $offset = $end;
        }
        return $intervals;
    }

    /**
     * @param array<int, array{int, int}> $intervals
     */
    private static function inside(array $intervals, int $pos): bool
    {
        foreach ($intervals as [$start, $end]) {
            if ($pos >= $start && $pos < $end) {
                return true;
            }
        }
        return false;
    }

    /**
     * Registra un errore o un limite per lo script corrente. Gli errori sono limitati; i flag sono sempre aggiornati.
     *
     * @param array<string, mixed> $acc
     */
    private static function flag(array &$acc, string $code, bool $syntaxDetermined, bool $inventoryComplete): void
    {
        $entry = ['code' => $code, 'index' => $acc['script'], 'locator' => 'script[' . $acc['script'] . ']'];
        if (count($acc['errors']) < self::MAX_ERRORS) {
            $acc['errors'][] = $entry;
        } elseif ($code === 'JSON_SYNTAX' && !self::hasSyntaxError($acc['errors'])) {
            // Log pieno senza prova sintattica: la prima prova sostituisce l'ultima voce. I flag restano sempre aggiornati.
            $acc['errors'][self::MAX_ERRORS - 1] = $entry;
        }
        if (!$syntaxDetermined) {
            $acc['syntax_complete'] = false;
        }
        if (!$inventoryComplete) {
            $acc['inventory_complete'] = false;
        }
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $acc
     */
    private static function walk($value, string $path, ?string $inherited, bool $named, bool $root, array &$acc): void
    {
        if (is_array($value)) {
            foreach ($value as $index => $item) {
                self::walk($item, $path . '[' . ((int) $index + 1) . ']', $inherited, $named, $root, $acc);
            }
            return;
        }
        if (is_object($value)) {
            self::node($value, $path, $inherited, $named, $root, $acc);
        }
    }

    /**
     * Oggetto JSON: contenitore @graph, riferimento, definizione di nodo e visita delle proprieta'.
     *
     * @param array<string, mixed> $acc
     */
    private static function node(object $obj, string $path, ?string $inherited, bool $named, bool $root, array &$acc): void
    {
        $vars = get_object_vars($obj);
        $keys = array_map('strval', array_keys($vars));
        if (in_array('@value', $keys, true)) {
            return;
        }
        if (in_array('@list', $keys, true) || in_array('@set', $keys, true)) {
            self::keyword($acc);
            return;
        }

        $context = array_key_exists('@context', $vars)
            ? (self::isSchemaContext($vars['@context']) ? 'schema' : 'other')
            : ($inherited ?? 'none');
        $own = array_values(array_diff($keys, ['@context', '@graph']));
        $isContainer = array_key_exists('@graph', $vars);
        $defaultGraph = $root && $own === [];
        if ($isContainer) {
            self::walk($vars['@graph'], $path . '/@graph', $context, $named || !$defaultGraph, false, $acc);
            if ($own === []) {
                return;
            }
        }

        $id = null;
        $idInvalid = false;
        if (array_key_exists('@id', $vars)) {
            if (is_string($vars['@id'])) {
                $id = $vars['@id'];
            } else {
                $idInvalid = true;
                self::flag($acc, 'NODE_ID_INVALID', true, false);
            }
        }
        if ($own === ['@id']) {
            if ($id !== null) {
                self::reference($id, $acc);
            }
            return;
        }
        if ($own === [] || $idInvalid) {
            return;
        }

        $profile = $context === 'schema' && !$named && !($isContainer && $own !== [])
            && ($id === null || preg_match(self::ABSOLUTE_HTTP, $id) === 1);
        $acc['node_count']++;
        if ($acc['node_count'] > self::MAX_NODES) {
            self::budgetNodes($acc);
        } else {
            $entry = ['id' => $id, 'types' => self::types($vars), 'script' => $acc['script'], 'locator' => $path,
                'profile' => $profile, 'url_state' => 'none'];
            if (array_key_exists('url', $vars)) {
                if (is_string($vars['url'])) {
                    $entry['url_state'] = 'scalar';
                    $entry['url'] = $vars['url'];
                } else {
                    $entry['url_state'] = 'ambiguous';
                }
            }
            $acc['nodes'][] = $entry;
        }
        if (!$profile) {
            $acc['profile_complete'] = false;
        }

        foreach ($vars as $key => $value) {
            $key = (string) $key;
            if ($key === '@context' || $key === '@graph' || $key === '@id' || $key === '@type') {
                continue;
            }
            if (strncmp($key, '@', 1) === 0) {
                // Keyword non espansa: valore strutturato = contenuto non visitato, quindi inventario incompleto.
                if (is_array($value) || is_object($value)) {
                    self::keyword($acc);
                }
                continue;
            }
            self::walk($value, $path . '/' . $key, $context, $named, false, $acc);
        }
    }

    /**
     * @param array<string, mixed> $acc
     */
    private static function reference(string $id, array &$acc): void
    {
        if (in_array($id, $acc['reference_ids'], true)) {
            return;
        }
        if (count($acc['reference_ids']) >= self::MAX_NODES) {
            self::budgetNodes($acc);
            return;
        }
        $acc['reference_ids'][] = $id;
    }

    /**
     * @param array<string, mixed> $vars
     * @return string[]
     */
    private static function types(array $vars): array
    {
        if (!array_key_exists('@type', $vars)) {
            return [];
        }
        $type = $vars['@type'];
        if (is_string($type)) {
            return [$type];
        }
        if (!is_array($type)) {
            return [];
        }
        foreach ($type as $item) {
            if (!is_string($item)) {
                return [];
            }
        }
        return array_values($type);
    }

    /**
     * @param mixed $context
     */
    private static function isSchemaContext($context): bool
    {
        return is_string($context) && in_array($context, self::SCHEMA_CONTEXTS, true);
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     */
    private static function hasSyntaxError(array $errors): bool
    {
        foreach ($errors as $error) {
            if ($error['code'] === 'JSON_SYNTAX') {
                return true;
            }
        }
        return false;
    }

    /**
     * Keyword JSON-LD non espansa (@list, @set, @included, @reverse, @nest, ignote con valore strutturato): la sintassi
     * resta valida, ma inventario e profilo non sono completi.
     *
     * @param array<string, mixed> $acc
     */
    private static function keyword(array &$acc): void
    {
        self::flag($acc, 'JSONLD_KEYWORD_UNSUPPORTED', true, false);
        $acc['profile_complete'] = false;
    }

    /**
     * Sforamento dei nodi: una sola voce per analisi, anche se i riferimenti e le definizioni eccedono.
     *
     * @param array<string, mixed> $acc
     */
    private static function budgetNodes(array &$acc): void
    {
        if ($acc['nodes_budget']) {
            return;
        }
        $acc['nodes_budget'] = true;
        self::flag($acc, 'BUDGET_NODES', true, false);
    }

    private function __construct()
    {
    }
}
