<?php

declare(strict_types=1);

namespace SeoLab\Capture;

/**
 * Parsing e risoluzione di URL per la cattura. Niente userinfo, niente IP letterali, solo http(s) con host DNS
 * a due o piu' etichette. La risoluzione dei riferimenti relativi segue RFC 3986 sezione 5.
 */
final class UrlTools
{
    private const SPLIT_PATTERN = '#^(([^:/?\#]+):)?(//([^/?\#]*))?([^?\#]*)(\?([^\#]*))?(\#(.*))?$#s';
    private const HOST_LABEL = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';
    private const ABSOLUTE_HTTP = '#^https?://[^\s]+\z#';

    /**
     * Valida un URL da richiedere. Restituisce schema, host, porta effettiva e URL originale, oppure null.
     *
     * @return array{scheme: string, host: string, port: int, url: string}|null
     */
    public static function parseFetchable(string $url): ?array
    {
        if (preg_match('/[\x00-\x20\x7F]/', $url) || !preg_match(self::ABSOLUTE_HTTP, $url)) {
            return null;
        }
        $parts = self::split($url);
        if ($parts['scheme'] === null || $parts['authority'] === null) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $authority = $parts['authority'];
        if (strpos($authority, '@') !== false || strpos($authority, '[') !== false) {
            return null;
        }
        if (!preg_match('/^([^:]*)(?::([0-9]{1,5}))?$/', $authority, $m)) {
            return null;
        }
        $host = strtolower($m[1]);
        if (!self::isDnsHost($host)) {
            return null;
        }
        $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return null;
        }
        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'url' => $url];
    }

    /**
     * @param array{scheme: string, host: string, port: int, url: string} $a
     * @param array{scheme: string, host: string, port: int, url: string} $b
     */
    public static function sameOrigin(array $a, array $b): bool
    {
        return $a['scheme'] === $b['scheme'] && $a['host'] === $b['host'] && $a['port'] === $b['port'];
    }

    /**
     * Risolve un riferimento (href o Location) rispetto a una base assoluta. Null se il risultato non e' un URL http(s) assoluto.
     */
    public static function resolve(string $base, string $ref): ?string
    {
        $b = self::split($base);
        $r = self::split($ref);
        if ($b['scheme'] === null || $b['authority'] === null) {
            return null;
        }
        if ($r['scheme'] !== null) {
            $target = $r;
            $target['path'] = self::removeDots($r['path']);
        } elseif ($r['authority'] !== null) {
            $target = $r;
            $target['scheme'] = $b['scheme'];
            $target['path'] = self::removeDots($r['path']);
        } else {
            $target = ['scheme' => $b['scheme'], 'authority' => $b['authority'], 'path' => '', 'query' => null, 'fragment' => $r['fragment']];
            if ($r['path'] === '') {
                $target['path'] = $b['path'];
                $target['query'] = $r['query'] ?? $b['query'];
            } else {
                $path = strpos($r['path'], '/') === 0 ? $r['path'] : self::merge($b, $r['path']);
                $target['path'] = self::removeDots($path);
                $target['query'] = $r['query'];
            }
        }
        if ($target['authority'] === null || $target['authority'] === '') {
            return null;
        }
        $out = $target['scheme'] . '://' . $target['authority'] . $target['path'];
        if ($target['query'] !== null) {
            $out .= '?' . $target['query'];
        }
        if ($target['fragment'] !== null) {
            $out .= '#' . $target['fragment'];
        }
        return preg_match(self::ABSOLUTE_HTTP, $out) ? $out : null;
    }

    /**
     * @return array{scheme: string|null, authority: string|null, path: string, query: string|null, fragment: string|null}
     */
    private static function split(string $url): array
    {
        preg_match(self::SPLIT_PATTERN, $url, $m, PREG_UNMATCHED_AS_NULL);
        return [
            'scheme' => $m[2] ?? null,
            'authority' => $m[4] ?? null,
            'path' => $m[5] ?? '',
            'query' => $m[7] ?? null,
            'fragment' => $m[9] ?? null,
        ];
    }

    /**
     * @param array{path: string} $base
     */
    private static function merge(array $base, string $relative): string
    {
        if ($base['path'] === '' || $base['path'] === null) {
            return '/' . $relative;
        }
        $cut = strrpos($base['path'], '/');
        return ($cut === false ? '' : substr($base['path'], 0, $cut + 1)) . $relative;
    }

    /**
     * Rimozione dei segmenti punto secondo RFC 3986 sezione 5.2.4.
     */
    private static function removeDots(string $path): string
    {
        $in = $path;
        $out = '';
        while ($in !== '') {
            if (strpos($in, '../') === 0) {
                $in = substr($in, 3);
            } elseif (strpos($in, './') === 0) {
                $in = substr($in, 2);
            } elseif (strpos($in, '/./') === 0) {
                $in = substr($in, 2);
            } elseif ($in === '/.') {
                $in = '/';
            } elseif (strpos($in, '/../') === 0) {
                $in = substr($in, 3);
                $out = self::dropLastSegment($out);
            } elseif ($in === '/..') {
                $in = '/';
                $out = self::dropLastSegment($out);
            } elseif ($in === '.' || $in === '..') {
                $in = '';
            } else {
                $from = strpos($in, '/', $in[0] === '/' ? 1 : 0);
                $segment = $from === false ? $in : substr($in, 0, $from);
                $out .= $segment;
                $in = $from === false ? '' : substr($in, $from);
            }
        }
        return $out;
    }

    private static function dropLastSegment(string $path): string
    {
        $cut = strrpos($path, '/');
        return $cut === false ? '' : substr($path, 0, $cut);
    }

    private static function isDnsHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if (!preg_match(self::HOST_LABEL, $label)) {
                return false;
            }
        }
        // Ultima etichetta non numerica: esclude gli IPv4 letterali (es. 1.2.3.4) come host DNS.
        return !ctype_digit($labels[count($labels) - 1]);
    }

    private function __construct()
    {
    }
}
