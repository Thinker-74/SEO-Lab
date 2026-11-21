<?php

declare(strict_types=1);

namespace SeoLab\Capture;

/**
 * Canonical dagli header HTTP Link (RFC 8288) della risposta finale. Riceve i valori ripetuti dell'header e
 * separa le voci solo fuori da riferimenti <...> e stringhe quotate. Esito: absent (nessuna voce canonical),
 * present (target assoluti in ordine) oppure unobservable: sintassi ambigua, anchor esplicito, voce canonical
 * non risolvibile, oltre 16 KiB aggregati o oltre 64 voci. Nessuna richiesta verso i target.
 */
final class LinkHeader
{
    private const MAX_BYTES = 16384;
    private const MAX_ENTRIES = 64;

    /**
     * @param string[] $values valori grezzi di tutti gli header Link della risposta finale
     * @return array{state: string, targets: string[]}
     */
    public static function canonicalTargets(array $values, string $finalUrl): array
    {
        $unobservable = ['state' => 'unobservable', 'targets' => []];
        $bytes = 0;
        foreach ($values as $value) {
            $bytes += strlen($value);
        }
        if ($bytes > self::MAX_BYTES) {
            return $unobservable;
        }

        $entries = [];
        foreach ($values as $value) {
            $parsed = self::entries($value);
            if ($parsed === null) {
                return $unobservable;
            }
            foreach ($parsed as $entry) {
                $entries[] = $entry;
            }
        }
        if (count($entries) > self::MAX_ENTRIES) {
            return $unobservable;
        }

        $targets = [];
        foreach ($entries as $entry) {
            if (!$entry['canonical']) {
                continue;
            }
            if ($entry['anchor'] || strpos($entry['uri'], '#') !== false || preg_match('/\s/', $entry['uri']) === 1) {
                return $unobservable;
            }
            $resolved = UrlTools::resolve($finalUrl, $entry['uri']);
            if ($resolved === null) {
                return $unobservable;
            }
            $targets[] = $resolved;
        }
        return ['state' => $targets === [] ? 'absent' : 'present', 'targets' => $targets];
    }

    /**
     * Voci di un singolo valore dell'header. Null se la sintassi e' ambigua.
     *
     * @return array<int, array{uri: string, canonical: bool, anchor: bool}>|null
     */
    private static function entries(string $value): ?array
    {
        $segments = self::split($value, ',', true);
        if ($segments === null) {
            return null;
        }
        $out = [];
        foreach ($segments as $segment) {
            $entry = self::entry(trim($segment));
            if ($entry === null) {
                return null;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @return array{uri: string, canonical: bool, anchor: bool}|null
     */
    private static function entry(string $segment): ?array
    {
        if ($segment === '' || $segment[0] !== '<') {
            return null;
        }
        $close = strpos($segment, '>');
        if ($close === false) {
            return null;
        }
        $uri = trim(substr($segment, 1, $close - 1));
        if ($uri === '') {
            return null;
        }
        $rest = trim(substr($segment, $close + 1));
        $canonical = false;
        $anchor = false;
        $relSeen = false;
        if ($rest !== '') {
            if ($rest[0] !== ';') {
                return null;
            }
            $params = self::split(substr($rest, 1), ';', false);
            if ($params === null) {
                return null;
            }
            foreach ($params as $param) {
                $pair = self::parameter(trim($param));
                if ($pair === null) {
                    return null;
                }
                list($name, $value) = $pair;
                if ($name === 'rel' && !$relSeen) {
                    // Per rel ripetuto vale il primo; il token canonical e' confrontato senza distinguere maiuscole.
                    // Il valore di rel e' obbligatorio: rel senza valore non e' canonical.
                    $relSeen = true;
                    if ($value !== null) {
                        $canonical = in_array('canonical', preg_split('/\s+/', strtolower(trim($value))) ?: [], true);
                    }
                }
                if ($name === 'anchor') {
                    $anchor = true;
                }
            }
        }
        return ['uri' => $uri, 'canonical' => $canonical, 'anchor' => $anchor];
    }

    /**
     * Parametro RFC 8288 link-param: token [ "=" ( token / quoted-string ) ]. Il valore e' opzionale (null se assente,
     * per esempio crossorigin o nopush). Null se malformato: nome vuoto, "=" senza valore o quotatura non chiusa.
     *
     * @return array{0: string, 1: string|null}|null
     */
    private static function parameter(string $param): ?array
    {
        $eq = strpos($param, '=');
        if ($eq === false) {
            $name = strtolower(trim($param));
            if ($name === '' || preg_match('/[\s"]/', $name) === 1) {
                return null;
            }
            return [$name, null];
        }
        $name = strtolower(trim(substr($param, 0, $eq)));
        $raw = trim(substr($param, $eq + 1));
        if ($name === '' || $raw === '') {
            return null;
        }
        if ($raw[0] === '"') {
            if (strlen($raw) < 2 || substr($raw, -1) !== '"') {
                return null;
            }
            $value = self::unquote(substr($raw, 1, -1));
            return $value === null ? null : [$name, $value];
        }
        if (preg_match('/[\s"]/', $raw) === 1) {
            return null;
        }
        return [$name, $raw];
    }

    /**
     * Rimuove gli escape di una stringa quotata. Null se contiene virgolette non escapate o escape incompleto.
     */
    private static function unquote(string $inner): ?string
    {
        $out = '';
        $length = strlen($inner);
        for ($i = 0; $i < $length; $i++) {
            $char = $inner[$i];
            if ($char === '\\') {
                if ($i + 1 >= $length) {
                    return null;
                }
                $i++;
                $out .= $inner[$i];
                continue;
            }
            if ($char === '"') {
                return null;
            }
            $out .= $char;
        }
        return $out;
    }

    /**
     * Divide il testo sul separatore fuori da stringhe quotate e, se richiesto, fuori da riferimenti <...>.
     * Null se una stringa quotata o un riferimento restano aperti.
     *
     * @return string[]|null
     */
    private static function split(string $text, string $separator, bool $angles): ?array
    {
        $parts = [];
        $current = '';
        $inQuote = false;
        $inAngle = false;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($inQuote) {
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $char . $text[$i + 1];
                    $i++;
                    continue;
                }
                if ($char === '"') {
                    $inQuote = false;
                }
                $current .= $char;
                continue;
            }
            if ($char === '"') {
                $inQuote = true;
                $current .= $char;
                continue;
            }
            if ($angles && $char === '<') {
                $inAngle = true;
            } elseif ($angles && $char === '>') {
                $inAngle = false;
            }
            if (!$inAngle && $char === $separator) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if ($inQuote || $inAngle) {
            return null;
        }
        $parts[] = $current;
        return $parts;
    }

    private function __construct()
    {
    }
}
