<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Osservazione di una singola capture: esito della capture, metadati HTTP, hash del body quando disponibile
 * e valori osservati per canonical, robots e JSON-LD. Non contiene HTML integrale.
 * Esito della capture, disponibilita' del dato osservato e causa restano campi indipendenti.
 */
final class ObservedSnapshot
{
    private const URL_PATTERN = '#^https?://[^\s]+\z#';
    private const CAPTURED_AT_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/';
    private const SHA256_PATTERN = '/^[0-9a-f]{64}\z/';
    private const CAPTURE_REASON_PATTERN = '/^[a-z_]{1,40}\z/';
    private const CACHE_HEADERS = ['age', 'etag', 'vary'];
    private const CACHE_VALUE_MAX = 256;
    private const POSITIONS_HEAD = ['head', 'outside_head'];

    /** @var string */
    private $status;

    /** @var string|null */
    private $captureReason;

    /** @var array<string, string> */
    private $cacheHeaders;

    /** @var array<string, string[]>|null */
    private $positions;

    /** @var string|null */
    private $requestedUrl;

    /** @var string|null */
    private $observedUrl;

    /** @var string|null */
    private $capturedAtUtc;

    /** @var int|null */
    private $httpStatus;

    /** @var string|null */
    private $contentType;

    /** @var string|null */
    private $bodySha256;

    /** @var string[] */
    private $redirects;

    /** @var string[] */
    private $xRobotsTag;

    /** @var ObservedValue[] */
    private $values;

    /**
     * @param array<string, mixed> $data chiavi: capture_status, requested_url, observed_url, captured_at_utc, http, observed_values
     */
    public function __construct(array $data)
    {
        $status = $data['capture_status'] ?? null;
        States::assertIn('capture_status', $status, States::SCAN_STATES);

        $http = $data['http'] ?? null;
        if (!is_array($http)) {
            throw new \InvalidArgumentException('http deve essere oggetto');
        }

        $httpStatus = $http['status'] ?? null;
        if ($httpStatus !== null && (!is_int($httpStatus) || $httpStatus < 100 || $httpStatus > 599)) {
            throw new \InvalidArgumentException('http.status non valido');
        }

        $contentType = $http['content_type'] ?? null;
        if ($contentType !== null && (!is_string($contentType) || $contentType === '')) {
            throw new \InvalidArgumentException('http.content_type non valido');
        }

        $bodySha256 = $http['body_sha256'] ?? null;
        if ($bodySha256 !== null) {
            if (!is_string($bodySha256) || !preg_match(self::SHA256_PATTERN, $bodySha256)) {
                throw new \InvalidArgumentException('http.body_sha256 non valido');
            }
            if (!in_array($status, [States::SCAN_OK, States::SCAN_PARTIAL], true)) {
                throw new \InvalidArgumentException('hash del body senza capture ok o partial');
            }
        }

        $redirects = $http['redirects'] ?? [];
        if (!ValueCopy::isList($redirects)) {
            throw new \InvalidArgumentException('http.redirects deve essere una lista');
        }
        foreach ($redirects as $redirect) {
            self::assertUrl($redirect, 'http.redirects');
        }

        $xRobotsTag = $http['x_robots_tag'] ?? [];
        if (!ValueCopy::isList($xRobotsTag)) {
            throw new \InvalidArgumentException('http.x_robots_tag deve essere una lista');
        }
        foreach ($xRobotsTag as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('http.x_robots_tag: valore non stringa');
            }
        }

        $cacheHeaders = $http['cache_headers'] ?? [];
        if (!is_array($cacheHeaders) || array_diff(array_keys($cacheHeaders), self::CACHE_HEADERS) !== []) {
            throw new \InvalidArgumentException('http.cache_headers: solo chiavi age, etag, vary');
        }
        foreach ($cacheHeaders as $name => $value) {
            if (!is_string($value) || $value === '' || strlen($value) > self::CACHE_VALUE_MAX || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new \InvalidArgumentException('http.cache_headers.' . $name . ' non valido');
            }
        }

        $captureReason = $data['capture_reason'] ?? null;
        if ($captureReason !== null && (!is_string($captureReason) || !preg_match(self::CAPTURE_REASON_PATTERN, $captureReason))) {
            throw new \InvalidArgumentException('capture_reason non valido');
        }

        $requestedUrl = self::optionalUrl($data['requested_url'] ?? null, 'requested_url');
        $observedUrl = self::optionalUrl($data['observed_url'] ?? null, 'observed_url');
        $capturedAtUtc = $data['captured_at_utc'] ?? null;
        if ($capturedAtUtc !== null && (!is_string($capturedAtUtc) || !preg_match(self::CAPTURED_AT_PATTERN, $capturedAtUtc))) {
            throw new \InvalidArgumentException('captured_at_utc deve essere UTC ISO 8601 (YYYY-MM-DDTHH:MM:SSZ)');
        }

        if (in_array($status, [States::SCAN_OK, States::SCAN_PARTIAL], true)) {
            if ($httpStatus === null || $contentType === null || $observedUrl === null || $capturedAtUtc === null) {
                throw new \InvalidArgumentException('capture ok o partial senza metadati HTTP obbligatori');
            }
        }

        $observedValues = $data['observed_values'] ?? null;
        if (!is_array($observedValues) || array_diff(array_keys($observedValues), RuleCatalog::families()) !== []) {
            throw new \InvalidArgumentException('observed_values deve essere un oggetto con canonical, robots e jsonld');
        }
        $values = [];
        foreach (RuleCatalog::families() as $family) {
            if (!isset($observedValues[$family]) || !is_array($observedValues[$family])) {
                throw new \InvalidArgumentException('observed_values.' . $family . ' mancante');
            }
            $values[$family] = new ObservedValue($family, $observedValues[$family], $status);
        }

        $positions = null;
        if (array_key_exists('observed_positions', $data) && $data['observed_positions'] !== null) {
            $positions = self::normalizePositions($data['observed_positions'], $values);
        }

        $this->status = $status;
        $this->captureReason = $captureReason;
        $this->cacheHeaders = $cacheHeaders;
        $this->positions = $positions;
        $this->requestedUrl = $requestedUrl;
        $this->observedUrl = $observedUrl;
        $this->capturedAtUtc = $capturedAtUtc;
        $this->httpStatus = $httpStatus;
        $this->contentType = $contentType;
        $this->bodySha256 = $bodySha256;
        $this->redirects = ValueCopy::detach($redirects, 'http.redirects');
        $this->xRobotsTag = ValueCopy::detach($xRobotsTag, 'http.x_robots_tag');
        $this->values = $values;
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * Motivo stabile della capture (codice in inglese), assente se non previsto dal chiamante.
     */
    public function captureReason(): ?string
    {
        return $this->captureReason;
    }

    /**
     * Intestazioni di cache in allowlist (age, etag, vary), assenti se non osservate.
     *
     * @return array<string, string>
     */
    public function cacheHeaders(): array
    {
        return $this->cacheHeaders;
    }

    /**
     * Posizioni DOM o di header per canonical e robots, allineate per indice ai valori osservati.
     *
     * @return array<string, string[]>|null
     */
    public function observedPositions(): ?array
    {
        return $this->positions;
    }

    /**
     * Campi di primo livello dello snapshot, nella forma letta dal costruttore.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'requested_url' => $this->requestedUrl,
            'observed_url' => $this->observedUrl,
            'captured_at_utc' => $this->capturedAtUtc,
            'capture_status' => $this->status,
        ];
        if ($this->captureReason !== null) {
            $out['capture_reason'] = $this->captureReason;
        }
        $out['http'] = $this->httpToArray();
        $out['observed_values'] = $this->valuesToArray();
        if ($this->positions !== null) {
            $out['observed_positions'] = $this->positions;
        }
        return $out;
    }

    public function requestedUrl(): ?string
    {
        return $this->requestedUrl;
    }

    public function observedUrl(): ?string
    {
        return $this->observedUrl;
    }

    public function capturedAtUtc(): ?string
    {
        return $this->capturedAtUtc;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function contentType(): ?string
    {
        return $this->contentType;
    }

    public function bodySha256(): ?string
    {
        return $this->bodySha256;
    }

    /**
     * @return string[]
     */
    public function redirects(): array
    {
        return $this->redirects;
    }

    /**
     * @return string[]
     */
    public function xRobotsTag(): array
    {
        return $this->xRobotsTag;
    }

    public function observedValue(string $family): ObservedValue
    {
        if (!isset($this->values[$family])) {
            throw new \InvalidArgumentException('famiglia osservata sconosciuta: ' . $family);
        }
        return $this->values[$family];
    }

    /**
     * @return array<string, mixed>
     */
    public function httpToArray(): array
    {
        $out = [
            'status' => $this->httpStatus,
            'content_type' => $this->contentType,
            'body_sha256' => $this->bodySha256,
            'redirects' => $this->redirects,
            'x_robots_tag' => $this->xRobotsTag,
        ];
        if ($this->cacheHeaders !== []) {
            $out['cache_headers'] = $this->cacheHeaders;
        }
        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function valuesToArray(): array
    {
        $out = [];
        foreach (RuleCatalog::families() as $family) {
            $out[$family] = $this->values[$family]->toArray();
        }
        return $out;
    }

    /**
     * Posizioni opzionali: liste per canonical e robots, di lunghezza pari ai valori osservati.
     * Canonical da meta: head o outside_head, da HTTP Link: http_header. Robots da meta: head o outside_head;
     * robots da header: solo http_header.
     *
     * @param mixed                        $positions
     * @param array<string, ObservedValue> $values
     * @return array<string, string[]>
     */
    private static function normalizePositions($positions, array $values): array
    {
        if (!is_array($positions) || array_diff(array_keys($positions), ['canonical', 'robots']) !== []) {
            throw new \InvalidArgumentException('observed_positions: solo chiavi canonical e robots');
        }
        $out = [];
        foreach (['canonical', 'robots'] as $family) {
            $list = $positions[$family] ?? [];
            if (!ValueCopy::isList($list)) {
                throw new \InvalidArgumentException('observed_positions.' . $family . ' deve essere una lista');
            }
            $items = $values[$family]->value() ?? [];
            if (count($list) !== count($items)) {
                throw new \InvalidArgumentException('observed_positions.' . $family . ': lunghezza diversa dai valori osservati');
            }
            foreach ($list as $index => $position) {
                if (!is_string($position)) {
                    throw new \InvalidArgumentException('observed_positions.' . $family . ': posizione non stringa');
                }
                if ($family === 'robots' && ($items[$index]['source'] ?? null) === 'header') {
                    if ($position !== 'http_header') {
                        throw new \InvalidArgumentException('observed_positions.robots: robots da header richiede http_header');
                    }
                    continue;
                }
                if ($family === 'canonical' && $position === 'http_header') {
                    continue;
                }
                if (!in_array($position, self::POSITIONS_HEAD, true)) {
                    throw new \InvalidArgumentException('observed_positions.' . $family . ': posizione fuori enum');
                }
            }
            $out[$family] = $list;
        }
        return $out;
    }

    private static function optionalUrl($value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        self::assertUrl($value, $field);
        return $value;
    }

    /**
     * @param mixed $value
     */
    private static function assertUrl($value, string $field): void
    {
        if (!is_string($value) || !preg_match(self::URL_PATTERN, $value)) {
            throw new \InvalidArgumentException($field . ' deve essere URL http(s) assoluto');
        }
    }
}
