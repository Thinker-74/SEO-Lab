<?php

declare(strict_types=1);

namespace SeoLab\Rules;

use SeoLab\Contracts\ObservedSnapshot;

/**
 * Target canonical osservati e confronto normalizzato in modo conservativo: schema e host in minuscolo, porta di
 * default eliminata; path, case del path, query, slash finale e codifica percent restano invariati.
 */
final class CanonicalTargets
{
    private const EXCERPT_MAX_BYTES = 1000;

    /**
     * Forma normalizzata di un URL http(s) assoluto senza fragment, oppure null.
     */
    public static function normalize(?string $url): ?string
    {
        if ($url === null || preg_match('~^(https?)://([^/?#]+)([^?#]*)(\?[^#]*)?(#.*)?\z~i', $url, $m) !== 1) {
            return null;
        }
        if (isset($m[5]) && $m[5] !== '') {
            return null;
        }
        $scheme = strtolower($m[1]);
        $host = $m[2];
        if (strpos($host, '@') !== false) {
            return null;
        }
        $port = null;
        if (preg_match('/^(.+):([0-9]{1,5})\z/', $host, $p) === 1) {
            $host = $p[1];
            $port = (int) $p[2];
        }
        $host = strtolower($host);
        if ($host === '') {
            return null;
        }
        if ($port !== null && $port !== ($scheme === 'https' ? 443 : 80)) {
            $host .= ':' . $port;
        }
        $path = $m[3] === '' ? '/' : $m[3];
        $query = $m[4] ?? '';
        return $scheme . '://' . $host . $path . $query;
    }

    /**
     * Target canonical con posizione e forma normalizzata. Null se la posizione o un target non sono affidabili.
     *
     * @return array<int, array{url: string, position: string, norm: string}>|null
     */
    public static function items(ObservedSnapshot $observed): ?array
    {
        $value = $observed->observedValue('canonical');
        if ($value->availability() === 'unobservable') {
            return null;
        }
        if ($value->availability() === 'absent') {
            return [];
        }
        $positions = ($observed->observedPositions() ?? [])['canonical'] ?? null;
        $urls = $value->value() ?? [];
        if ($positions === null || count($positions) !== count($urls)) {
            return null;
        }
        $items = [];
        foreach ($urls as $index => $url) {
            $norm = self::normalize($url);
            if ($norm === null) {
                return null;
            }
            $items[] = ['url' => $url, 'position' => $positions[$index], 'norm' => $norm];
        }
        return $items;
    }

    /**
     * Candidati per il confronto: posizioni head e header Link, un solo item per target normalizzato.
     *
     * @param array<int, array{url: string, position: string, norm: string}> $items
     * @return array<int, array{url: string, position: string, norm: string}>
     */
    public static function candidates(array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            if ($item['position'] === 'outside_head' || isset($seen[$item['norm']])) {
                continue;
            }
            $seen[$item['norm']] = true;
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Target fuori head: solo contesto, esclusi dal conteggio dei candidati.
     *
     * @param array<int, array{url: string, position: string, norm: string}> $items
     * @return array<int, array{url: string, position: string, norm: string}>
     */
    public static function context(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($item['position'] === 'outside_head') {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Evidenza circoscritta di un target: meta/link con posizione, oppure header Link con il target come dato derivato.
     *
     * @param array{url: string, position: string, norm: string} $item
     * @return array<string, string>
     */
    public static function evidence(array $item): array
    {
        if ($item['position'] === 'http_header') {
            return ['kind' => 'header', 'name' => 'Link', 'excerpt' => self::excerpt($item['url'])];
        }
        return [
            'kind' => 'html',
            'locator' => "link[@rel='canonical'] (" . $item['position'] . ')',
            'excerpt' => self::excerpt("<link rel='canonical' href='" . $item['url'] . "'>"),
        ];
    }

    /**
     * Estratto limitato in byte e in UTF-8 valido, per il contratto Finding.
     */
    public static function excerpt(string $text): string
    {
        while (strlen($text) > self::EXCERPT_MAX_BYTES) {
            $text = substr($text, 0, -1);
        }
        while ($text !== '' && preg_match('//u', $text) !== 1) {
            $text = substr($text, 0, -1);
        }
        return $text;
    }

    private function __construct()
    {
    }
}
