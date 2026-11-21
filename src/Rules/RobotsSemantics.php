<?php

declare(strict_types=1);

namespace SeoLab\Rules;

use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;

/**
 * Semantica dei robots per Googlebot testuale. Ambito: direttive senza bot (meta robots, X-Robots-Tag senza prefisso)
 * e direttive con nome googlebot; gli altri bot sono fuori scope. Le restrizioni si cumulano; index e follow sono il
 * default su risposta completa, noindex e nofollow prevalgono, all e none sono coppie equivalenti a index,follow
 * e noindex,nofollow. Le dimensioni normalizzabili sono index, follow, snippet (nosnippet equivale a max-snippet:0),
 * anteprima immagini, anteprima video, noimageindex, noarchive, notranslate. Sintassi non valida o direttive
 * contestuali (unavailable_after) rendono il confronto non valutabile.
 */
final class RobotsSemantics
{
    private const UNLIMITED = PHP_INT_MAX;

    private const KNOWN = [
        'index', 'noindex', 'follow', 'nofollow', 'all', 'none', 'nosnippet', 'max-snippet', 'max-image-preview',
        'max-video-preview', 'noimageindex', 'noarchive', 'notranslate', 'unavailable_after', 'noodp', 'noydir',
        'indexifembedded', 'nocache',
    ];

    private const IMAGE_RANK = ['none' => 0, 'standard' => 1, 'large' => 2];

    private const SCOPES = ['all', 'googlebot'];

    /**
     * Osservato: direttive in ambito con posizione. Null se la famiglia robots non e' leggibile in modo affidabile.
     *
     * @return array{items: array<int, array<string, mixed>>, directives: string[]}|null
     */
    public static function observed(ObservedSnapshot $observed): ?array
    {
        $value = $observed->observedValue('robots');
        if ($value->availability() === 'unobservable') {
            return null;
        }
        if ($value->availability() === 'absent') {
            return ['items' => [], 'directives' => []];
        }
        $positions = ($observed->observedPositions() ?? [])['robots'] ?? null;
        $list = $value->value() ?? [];
        if ($positions === null || count($positions) !== count($list)) {
            return null;
        }
        $items = [];
        $directives = [];
        foreach ($list as $index => $item) {
            if (!in_array($item['bot'], self::SCOPES, true)) {
                continue;
            }
            $directive = strtolower(trim($item['directive']));
            $items[] = [
                'index' => $index,
                'bot' => $item['bot'],
                'source' => $item['source'],
                'directive' => $directive,
                'position' => $positions[$index],
            ];
            $directives[] = $directive;
        }
        return ['items' => $items, 'directives' => $directives];
    }

    /**
     * Atteso Yoast: direttive in ambito. Il prefisso "bot: " con nome non noto come direttiva indica l'ambito del bot.
     *
     * @return array{directives: string[]}|null null se robots non e' disponibile
     */
    public static function expected(ExpectedSnapshot $expected): ?array
    {
        $value = $expected->resolved('robots');
        if ($value->availability() !== 'available') {
            return null;
        }
        $raw = $value->value();
        $entries = is_array($raw) ? $raw : explode(',', (string) $raw);
        $directives = [];
        foreach ($entries as $entry) {
            $entry = trim((string) $entry);
            $colon = strpos($entry, ':');
            if ($colon !== false) {
                $prefix = strtolower(trim(substr($entry, 0, $colon)));
                if (!in_array($prefix, self::KNOWN, true)) {
                    if (!in_array($prefix, self::SCOPES, true)) {
                        continue;
                    }
                    $entry = trim(substr($entry, $colon + 1));
                }
            }
            $directives[] = strtolower($entry);
        }
        return ['directives' => $directives];
    }

    /**
     * Dimensioni effettive per un insieme di direttive in ambito.
     *
     * @param string[] $directives
     * @return array{valid: bool, contextual: bool, dims: array<string, mixed>}
     */
    public static function effective(array $directives): array
    {
        $dims = [
            'index' => 'index',
            'follow' => 'follow',
            'snippet' => self::UNLIMITED,
            'image' => null,
            'video' => self::UNLIMITED,
            'imageindex' => 'imageindex',
            'archive' => 'archive',
            'translate' => 'translate',
        ];
        $valid = true;
        $contextual = false;
        foreach ($directives as $raw) {
            $directive = strtolower(trim($raw));
            if ($directive === '') {
                continue;
            }
            $name = $directive;
            $value = null;
            $colon = strpos($directive, ':');
            if ($colon !== false) {
                $name = trim(substr($directive, 0, $colon));
                $value = trim(substr($directive, $colon + 1));
            }
            switch ($name) {
                case 'noindex':
                    $dims['index'] = 'noindex';
                    break;
                case 'nofollow':
                    $dims['follow'] = 'nofollow';
                    break;
                case 'none':
                    $dims['index'] = 'noindex';
                    $dims['follow'] = 'nofollow';
                    break;
                case 'nosnippet':
                    $dims['snippet'] = min($dims['snippet'], 0);
                    break;
                case 'max-snippet':
                    $number = self::number($value);
                    if ($number === null) {
                        $valid = false;
                    } else {
                        $dims['snippet'] = min($dims['snippet'], $number);
                    }
                    break;
                case 'max-video-preview':
                    $number = self::number($value);
                    if ($number === null) {
                        $valid = false;
                    } else {
                        $dims['video'] = min($dims['video'], $number);
                    }
                    break;
                case 'max-image-preview':
                    if (!isset(self::IMAGE_RANK[$value ?? ''])) {
                        $valid = false;
                    } else {
                        $rank = self::IMAGE_RANK[$value];
                        $dims['image'] = $dims['image'] === null ? $rank : min($dims['image'], $rank);
                    }
                    break;
                case 'noimageindex':
                    $dims['imageindex'] = 'noimageindex';
                    break;
                case 'noarchive':
                    $dims['archive'] = 'noarchive';
                    break;
                case 'notranslate':
                    $dims['translate'] = 'notranslate';
                    break;
                case 'unavailable_after':
                    $contextual = true;
                    break;
                default:
                    break;
            }
        }
        // Senza direttiva di anteprima immagini vale il default "standard" (documentato da Google).
        if ($dims['image'] === null) {
            $dims['image'] = self::IMAGE_RANK['standard'];
        }
        return ['valid' => $valid, 'contextual' => $contextual, 'dims' => $dims];
    }

    /**
     * Dimensioni che differiscono. Con noindex su entrambi i lati le dimensioni dell'indicizzazione non si confrontano.
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $observed
     * @return string[]
     */
    public static function differences(array $expected, array $observed): array
    {
        $keys = array_keys($expected);
        if ($expected['index'] === 'noindex' && $observed['index'] === 'noindex') {
            $keys = ['index', 'follow'];
        }
        $out = [];
        foreach ($keys as $key) {
            if ($expected[$key] !== $observed[$key]) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * 'yes' o 'no' se la direttiva noindex in ambito e' effettiva, 'unknown' se robots non e' valutabile.
     */
    public static function noindexState(ObservedSnapshot $observed): string
    {
        $obs = self::observed($observed);
        if ($obs === null) {
            return 'unknown';
        }
        $eff = self::effective($obs['directives']);
        if (!$eff['valid']) {
            return 'unknown';
        }
        return $eff['dims']['index'] === 'noindex' ? 'yes' : 'no';
    }

    /**
     * Dimensioni toccate da una direttiva (per la scelta delle evidenze).
     *
     * @return string[]
     */
    public static function touches(string $directive): array
    {
        $name = $directive;
        $colon = strpos($directive, ':');
        if ($colon !== false) {
            $name = trim(substr($directive, 0, $colon));
        }
        switch ($name) {
            case 'noindex':
                return ['index'];
            case 'none':
                return ['index', 'follow'];
            case 'nofollow':
                return ['follow'];
            case 'nosnippet':
            case 'max-snippet':
                return ['snippet'];
            case 'max-image-preview':
                return ['image'];
            case 'max-video-preview':
                return ['video'];
            case 'noimageindex':
                return ['imageindex'];
            case 'noarchive':
                return ['archive'];
            case 'notranslate':
                return ['translate'];
            default:
                return [];
        }
    }

    /**
     * Evidenze delle direttive osservate che toccano le dimensioni indicate. Vuoto se nessuna direttiva le tocca.
     *
     * @param array<int, array<string, mixed>> $items
     * @param string[] $dims
     * @return array<int, array<string, string>>
     */
    public static function evidence(array $items, array $dims): array
    {
        $out = [];
        foreach ($items as $item) {
            if (array_intersect(self::touches($item['directive']), $dims) === []) {
                continue;
            }
            if ($item['source'] === 'header') {
                $out[] = ['kind' => 'header', 'name' => 'X-Robots-Tag', 'excerpt' => $item['directive']];
                continue;
            }
            $name = $item['bot'] === 'all' ? 'robots' : $item['bot'];
            $out[] = [
                'kind' => 'html',
                'locator' => "meta[@name='" . $name . "'] (" . $item['position'] . ')',
                'excerpt' => "<meta name='" . $name . "' content='" . $item['directive'] . "'>",
            ];
        }
        return $out;
    }

    private static function number(?string $value): ?int
    {
        if ($value === null || preg_match('/^-?[0-9]{1,9}\z/', $value) !== 1) {
            return null;
        }
        $number = (int) $value;
        return $number < 0 ? self::UNLIMITED : $number;
    }

    private function __construct()
    {
    }
}
