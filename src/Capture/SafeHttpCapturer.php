<?php

declare(strict_types=1);

namespace SeoLab\Capture;

use SeoLab\Contracts\ContentCapturer;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\TrustedContent;

/**
 * Cattura HTTP difensiva di una singola pagina pubblicata, a partire dall'ID WordPress.
 * Ogni hop: validazione di origine e DNS, budget di tempo residuo, wp_safe_remote_get senza redirect automatici,
 * corpo in file temporaneo fuori dall'area servita con limite, pulizia del file in finally.
 * Esito: ObservedSnapshot con stato separato (ok, partial, blocked, failed) e motivo stabile.
 */
final class SafeHttpCapturer implements ContentCapturer
{
    private const TIMEOUT_BUDGET = 5.0;
    /** Residuo minimo in secondi: sotto 1 ms il timeout arrotondato a 0 ms diventerebbe illimitato in cURL. */
    private const MIN_TIMEOUT = 0.001;
    private const MAX_REDIRECTS = 3;
    private const REDIRECT_CODES = [301, 302, 303, 307, 308];
    private const BLOCKED_CODES = [401, 403, 429];
    private const USER_AGENT = 'SEO-Lab/0.1';
    private const ACCEPT = 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1';
    private const CACHE_HEADERS = ['age', 'etag', 'vary'];
    private const CACHE_VALUE_MAX = 256;

    /** @var AddressResolver */
    private $resolver;

    /** @var callable */
    private $monotonic;

    /** @var string */
    private $tempDir;

    /** @var bool */
    private $domAvailable;

    /** @var int */
    private $bodyLimit;

    /**
     * @param callable $monotonic orologio monotono in secondi (float)
     * @param string   $tempDir   area temporanea fuori dall'area servita
     * @param int      $bodyLimit limite del corpo in byte (default 1 MiB), con un byte sentinella nel trasporto
     */
    public function __construct(AddressResolver $resolver, callable $monotonic, string $tempDir, bool $domAvailable = true, int $bodyLimit = 1048576)
    {
        if ($bodyLimit <= 0) {
            throw new \InvalidArgumentException('soglia del corpo deve essere positiva');
        }
        $this->resolver = $resolver;
        $this->monotonic = $monotonic;
        $this->tempDir = $tempDir;
        $this->domAvailable = $domAvailable;
        $this->bodyLimit = $bodyLimit;
    }

    public function capture(TrustedContent $content): ObservedSnapshot
    {
        return $this->run($content->postId(), $content->permalink());
    }

    public function captureForPost(int $postId): ObservedSnapshot
    {
        return $this->run($postId, null);
    }

    private function run(int $postId, ?string $trustedPermalink): ObservedSnapshot
    {
        $capturedAt = gmdate('Y-m-d\TH:i:s\Z');
        $deadline = (float) call_user_func($this->monotonic) + self::TIMEOUT_BUDGET;

        // Selezione dal core: nessuna richiesta HTTP prima di questo blocco.
        $post = get_post($postId);
        $permalink = get_permalink($postId);
        $requested = is_string($permalink) && preg_match('#^https?://[^\s]+\z#', $permalink) ? $permalink : null;
        if (!is_object($post) || ($post->post_status ?? null) !== 'publish'
            || !in_array($post->post_type ?? null, ['post', 'page'], true)
            || ($post->post_password ?? '') !== '') {
            return $this->outcome('blocked', 'content_ineligible', $requested, $capturedAt);
        }
        if (!is_string($permalink) || $permalink === '') {
            return $this->outcome('blocked', 'content_ineligible', $requested, $capturedAt);
        }
        if ($trustedPermalink !== null && $trustedPermalink !== $permalink) {
            return $this->outcome('blocked', 'content_ineligible', $requested, $capturedAt);
        }

        $origin = UrlTools::parseFetchable((string) home_url('/'));
        if ($origin === null) {
            return $this->outcome('blocked', 'origin_not_public', $requested, $capturedAt);
        }
        $target = UrlTools::parseFetchable($permalink);
        if ($target === null || !UrlTools::sameOrigin($target, $origin)) {
            return $this->outcome('blocked', 'content_ineligible', $requested, $capturedAt);
        }

        return $this->fetch($permalink, $origin, $requested, $capturedAt, $deadline);
    }

    /**
     * @param array{scheme: string, host: string, port: int, url: string} $origin
     */
    private function fetch(string $permalink, array $origin, ?string $requested, string $capturedAt, float $deadline): ObservedSnapshot
    {
        $current = $permalink;
        $visited = [$permalink => true];
        $redirects = [];

        while (true) {
            $parts = UrlTools::parseFetchable($current);
            if ($parts === null || !UrlTools::sameOrigin($parts, $origin)) {
                return $this->outcome('blocked', 'redirect_rejected', $requested, $capturedAt, $redirects);
            }

            // Validazione DNS per ogni hop, prima del trasporto.
            $addresses = $this->resolver->resolve($parts['host']);
            if ($addresses === []) {
                return $this->outcome('failed', 'origin_resolution_failed', $requested, $capturedAt, $redirects);
            }
            foreach ($addresses as $address) {
                if (!IpClassifier::isPublic((string) $address)) {
                    return $this->outcome('blocked', 'origin_not_public', $requested, $capturedAt, $redirects);
                }
            }

            if ($deadline - (float) call_user_func($this->monotonic) < self::MIN_TIMEOUT) {
                return $this->outcome('failed', 'timeout', $requested, $capturedAt, $redirects);
            }

            $tmp = $this->createTempFile();
            if ($tmp === false) {
                return $this->outcome('failed', 'temporary_storage_unavailable', $requested, $capturedAt, $redirects);
            }

            try {
                // Ricontrollo subito prima della GET: un residuo sotto soglia non diventa timeout "illimitato" (cURL con 0 ms).
                $remaining = $deadline - (float) call_user_func($this->monotonic);
                if ($remaining < self::MIN_TIMEOUT) {
                    return $this->outcome('failed', 'timeout', $requested, $capturedAt, $redirects);
                }
                $response = wp_safe_remote_get($current, $this->requestArgs($remaining, $tmp));

                if ($response instanceof \WP_Error) {
                    $message = (string) $response->get_error_message();
                    $reason = preg_match('/timed?\s*out/i', $message) ? 'timeout' : 'transport_error';
                    return $this->outcome('failed', $reason, $requested, $capturedAt, $redirects);
                }
                if (!is_array($response)) {
                    return $this->outcome('failed', 'transport_error', $requested, $capturedAt, $redirects);
                }

                // Il codice HTTP e' dato non attendibile: fuori da 100-599 non entra nello snapshot.
                $rawCode = $response['response']['code'] ?? null;
                $code = is_int($rawCode) || (is_string($rawCode) && ctype_digit($rawCode)) ? (int) $rawCode : 0;
                if ($code < 100 || $code > 599) {
                    return $this->outcome('failed', 'http_status_unexpected', $requested, $capturedAt, $redirects);
                }
                $headers = self::normalizeHeaders($response['headers'] ?? []);

                if (in_array($code, self::REDIRECT_CODES, true)) {
                    if (count($redirects) >= self::MAX_REDIRECTS) {
                        return $this->outcome('blocked', 'redirect_limit', $requested, $capturedAt, $redirects, $code);
                    }
                    $location = self::singleLocation($headers);
                    $next = $location === null ? null : UrlTools::resolve($current, $location);
                    if ($next === null || isset($visited[$next])) {
                        return $this->outcome('blocked', 'redirect_rejected', $requested, $capturedAt, $redirects, $code);
                    }
                    $nextParts = UrlTools::parseFetchable($next);
                    if ($nextParts === null || !UrlTools::sameOrigin($nextParts, $origin)) {
                        return $this->outcome('blocked', 'redirect_rejected', $requested, $capturedAt, $redirects, $code);
                    }
                    $visited[$next] = true;
                    $redirects[] = $next;
                    $current = $next;
                    continue;
                }

                if (in_array($code, self::BLOCKED_CODES, true)) {
                    return $this->outcome('blocked', 'http_blocked', $requested, $capturedAt, $redirects, $code);
                }

                foreach ($headers['content-encoding'] ?? [] as $encoding) {
                    $value = strtolower(trim((string) $encoding));
                    if ($value !== '' && $value !== 'identity') {
                        return $this->outcome('failed', 'content_encoding_unsupported', $requested, $capturedAt, $redirects, $code);
                    }
                }

                if ($code !== 200 && $code !== 206) {
                    return $this->outcome('failed', 'http_status_unexpected', $requested, $capturedAt, $redirects, $code);
                }

                return $this->bodyOutcome($code, $headers, $tmp, $current, $requested, $capturedAt, $redirects);
            } finally {
                $this->removeTempFile($tmp);
            }
        }
    }

    /**
     * @param array<string, string[]> $headers
     * @param string[]                $redirects
     */
    private function bodyOutcome(int $code, array $headers, string $tmp, string $current, ?string $requested, string $capturedAt, array $redirects): ObservedSnapshot
    {
        $contentTypes = $headers['content-type'] ?? [];
        if (count($contentTypes) !== 1) {
            return $this->outcome('failed', 'unsupported_response', $requested, $capturedAt, $redirects, $code);
        }
        $contentType = $contentTypes[0];
        $mediaType = strtolower(trim(explode(';', $contentType)[0]));
        if ($mediaType !== 'text/html') {
            return $this->outcome('failed', 'unsupported_response', $requested, $capturedAt, $redirects, $code, $contentType);
        }
        $httpCharset = self::charsetParameter($contentType);
        $xRobots = $headers['x-robots-tag'] ?? [];
        $cache = self::cacheHeaders($headers);

        clearstatcache(true, $tmp);
        $size = filesize($tmp);
        $handle = $size === false ? false : fopen($tmp, 'rb');
        if ($handle === false) {
            return $this->outcome('failed', 'transport_error', $requested, $capturedAt, $redirects, $code, $contentType);
        }
        $bytes = (string) fread($handle, $this->bodyLimit + 1);
        fclose($handle);

        $overLimit = strlen($bytes) > $this->bodyLimit;
        if ($overLimit) {
            $bytes = substr($bytes, 0, $this->bodyLimit);
        }
        $announced = isset($headers['content-length'][0]) && preg_match('/^\d{1,12}$/', $headers['content-length'][0])
            ? (int) $headers['content-length'][0]
            : null;

        $partialReason = null;
        if ($overLimit) {
            $partialReason = 'body_limit';
        } elseif ($code === 206) {
            $partialReason = 'partial_content';
        } elseif ($announced !== null && $announced > strlen($bytes)) {
            $partialReason = 'body_truncated';
        }
        if ($partialReason !== null) {
            return $this->build([
                'capture_status' => 'partial',
                'capture_reason' => $partialReason,
                'requested_url' => $requested,
                'observed_url' => $current,
                'captured_at_utc' => $capturedAt,
                'http' => [
                    'status' => $code,
                    'content_type' => $contentType,
                    'body_sha256' => hash('sha256', $bytes),
                    'redirects' => $redirects,
                    'x_robots_tag' => $xRobots,
                    'cache_headers' => $cache,
                ],
                'observed_values' => self::unobservableValues(),
            ]);
        }

        $extracted = HtmlExtractor::extract($bytes, $httpCharset, $current, $this->domAvailable, $xRobots);
        if (isset($extracted['error'])) {
            return $this->outcome('failed', (string) $extracted['error'], $requested, $capturedAt, $redirects, $code, $contentType);
        }
        $canonical = self::mergeCanonical(
            $extracted['values']['canonical'],
            $extracted['positions']['canonical'],
            LinkHeader::canonicalTargets($headers['link'] ?? [], $current)
        );
        $extracted['values']['canonical'] = $canonical['value'];
        $extracted['positions']['canonical'] = $canonical['positions'];

        return $this->build([
            'capture_status' => 'ok',
            'capture_reason' => null,
            'requested_url' => $requested,
            'observed_url' => $current,
            'captured_at_utc' => $capturedAt,
            'http' => [
                'status' => $code,
                'content_type' => $contentType,
                'body_sha256' => hash('sha256', $bytes),
                'redirects' => $redirects,
                'x_robots_tag' => $xRobots,
                'cache_headers' => $cache,
            ],
            'observed_values' => $extracted['values'],
            'observed_positions' => $extracted['positions'],
        ]);
    }

    /**
     * Esito senza corpo osservato: tutte le famiglie unobservable.
     *
     * @param string[] $redirects
     */
    private function outcome(string $status, string $reason, ?string $requested, string $capturedAt, array $redirects = [], ?int $httpStatus = null, ?string $contentType = null): ObservedSnapshot
    {
        return $this->build([
            'capture_status' => $status,
            'capture_reason' => $reason,
            'requested_url' => $requested,
            'observed_url' => null,
            'captured_at_utc' => $capturedAt,
            'http' => [
                'status' => $httpStatus,
                'content_type' => $contentType,
                'body_sha256' => null,
                'redirects' => $redirects,
                'x_robots_tag' => [],
            ],
            'observed_values' => self::unobservableValues(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function build(array $data): ObservedSnapshot
    {
        return new ObservedSnapshot($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestArgs(float $timeout, string $tmp): array
    {
        return [
            'redirection' => 0,
            'sslverify' => true,
            'timeout' => $timeout,
            'blocking' => true,
            'cookies' => [],
            'decompress' => false,
            'stream' => true,
            'filename' => $tmp,
            'limit_response_size' => $this->bodyLimit + 1,
            'user-agent' => self::USER_AGENT,
            'headers' => [
                'Accept' => self::ACCEPT,
                'Accept-Encoding' => 'identity',
            ],
        ];
    }

    /**
     * @return string|false
     */
    private function createTempFile()
    {
        if (!is_dir($this->tempDir) || !is_writable($this->tempDir)) {
            return false;
        }
        return @tempnam($this->tempDir, 'seolab-');
    }

    private function removeTempFile(string $tmp): void
    {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }

    /**
     * Intestazioni in minuscolo, ogni nome con la lista dei valori (stringhe). Accetta array e oggetti iterabili.
     *
     * @param mixed $headers
     * @return array<string, string[]>
     */
    private static function normalizeHeaders($headers): array
    {
        $out = [];
        if (!is_array($headers) && !$headers instanceof \Traversable) {
            return $out;
        }
        foreach ($headers as $name => $value) {
            $key = strtolower((string) $name);
            $items = is_array($value) || $value instanceof \Traversable ? $value : [$value];
            foreach ($items as $item) {
                if (is_string($item) || is_int($item) || is_float($item)) {
                    $text = trim((string) $item);
                    if ($text !== '') {
                        $out[$key][] = $text;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, string[]> $headers
     */
    private static function singleLocation(array $headers): ?string
    {
        $locations = $headers['location'] ?? [];
        if (count($locations) !== 1 || $locations[0] === '' || strpos($locations[0], ',') !== false) {
            return null;
        }
        return $locations[0];
    }

    private static function charsetParameter(string $contentType): ?string
    {
        if (preg_match('/charset\s*=\s*"?([^";\s]+)/i', $contentType, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }

    /**
     * @param array<string, string[]> $headers
     * @return array<string, string>
     */
    private static function cacheHeaders(array $headers): array
    {
        $out = [];
        foreach (self::CACHE_HEADERS as $name) {
            if (!isset($headers[$name])) {
                continue;
            }
            $value = trim(implode(', ', $headers[$name]));
            if ($value === '' || strlen($value) > self::CACHE_VALUE_MAX || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                continue;
            }
            $out[$name] = $value;
        }
        return $out;
    }

    /**
     * Unisce i canonical del documento (head e fuori head) con quelli dell'header Link. Un'ambiguita' in una delle
     * due fonti rende l'intera famiglia unobservable. Le posizioni restano allineate ai target.
     *
     * @param array<string, mixed> $html     famiglia canonical estratta dal documento
     * @param string[]|null       $htmlPositions posizioni del documento
     * @param array{state: string, targets: string[]} $link canonical dell'header Link
     * @return array{value: array<string, mixed>, positions: string[]}
     */
    private static function mergeCanonical(array $html, ?array $htmlPositions, array $link): array
    {
        if ($html['availability'] === 'unobservable' || $link['state'] === 'unobservable') {
            return ['value' => ['availability' => 'unobservable', 'value' => null], 'positions' => []];
        }
        $items = $html['availability'] === 'present' ? $html['value'] : [];
        $positions = $html['availability'] === 'present' ? $htmlPositions : [];
        foreach ($link['targets'] as $target) {
            $items[] = $target;
            $positions[] = 'http_header';
        }
        if ($items === []) {
            return ['value' => ['availability' => 'absent', 'value' => []], 'positions' => []];
        }
        return ['value' => ['availability' => 'present', 'value' => $items], 'positions' => $positions];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function unobservableValues(): array
    {
        return [
            'canonical' => ['availability' => 'unobservable', 'value' => null],
            'robots' => ['availability' => 'unobservable', 'value' => null],
            'jsonld' => ['availability' => 'unobservable', 'value' => null],
        ];
    }
}
