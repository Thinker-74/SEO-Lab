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

    /** @var string */
    private $status;

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

        $this->status = $status;
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
        return [
            'status' => $this->httpStatus,
            'content_type' => $this->contentType,
            'body_sha256' => $this->bodySha256,
            'redirects' => $this->redirects,
            'x_robots_tag' => $this->xRobotsTag,
        ];
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
