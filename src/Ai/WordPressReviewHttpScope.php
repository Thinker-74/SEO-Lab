<?php

declare(strict_types=1);

namespace SeoLab\Ai;

/**
 * Guardia HTTP della revisione AI opzionale (P1.1). Vive solo tra enter() e leave(): ammette una richiesta per
 * fase verso un host fisso, con metodo e percorso attesi, e impone timeout residuo, TLS, zero redirect, zero
 * cookie e limite di risposta. Il motivo di un rifiuto resta nella guardia, cosi' non dipende dal codice che il
 * trasporto Core puo' perdere.
 */
final class WordPressReviewHttpScope
{
    public const DEADLINE_NS = 12000000000;
    public const MAX_RESPONSE_BYTES = 262144;
    public const REASON_SCOPE = 'scope';
    public const REASON_TIMEOUT = 'timeout';

    /** @var string */
    private $host;

    /** @var callable */
    private $now;

    /** @var int|null */
    private $deadline;

    /** @var bool */
    private $active = false;

    /** @var string|null */
    private $method;

    /** @var string|null */
    private $path;

    /** @var int */
    private $attempts = 0;

    /** @var string|null */
    private $reason;

    /**
     * @param callable $now Orologio monotono in nanosecondi, restituisce int.
     */
    public function __construct(string $host, callable $now)
    {
        $this->host = $host;
        $this->now = $now;
    }

    public function enter(): void
    {
        $this->deadline = $this->clock() + self::DEADLINE_NS;
        $this->reason = null;
        $this->active = true;
        add_filter('http_request_args', [$this, 'filterArgs'], PHP_INT_MAX, 2);
        add_filter('pre_http_request', [$this, 'filterPre'], PHP_INT_MAX, 3);
    }

    public function leave(): void
    {
        remove_filter('http_request_args', [$this, 'filterArgs'], PHP_INT_MAX);
        remove_filter('pre_http_request', [$this, 'filterPre'], PHP_INT_MAX);
        $this->active = false;
        $this->method = null;
        $this->path = null;
    }

    /**
     * Apre una fase: una sola richiesta con questo metodo e percorso.
     */
    public function allow(string $method, string $path): void
    {
        $this->method = $method;
        $this->path = $path;
        $this->attempts = 0;
    }

    /**
     * @return string|null REASON_SCOPE o REASON_TIMEOUT al primo rifiuto, altrimenti null.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }

    public function expired(): bool
    {
        return $this->deadline === null || $this->clock() >= $this->deadline;
    }

    public function residualSeconds(): float
    {
        if ($this->deadline === null) {
            return 0.0;
        }
        return max(0.0, ($this->deadline - $this->clock()) / 1000000000);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function filterArgs($args, $url)
    {
        if (!$this->active || !is_array($args)) {
            return $args;
        }
        $args['timeout'] = $this->residualSeconds();
        $args['sslverify'] = true;
        $args['redirection'] = 0;
        $args['cookies'] = [];
        $args['limit_response_size'] = self::MAX_RESPONSE_BYTES;
        if (isset($args['headers']) && is_array($args['headers'])) {
            foreach (array_keys($args['headers']) as $name) {
                if (strtolower((string) $name) === 'cookie') {
                    unset($args['headers'][$name]);
                }
            }
        }
        return $args;
    }

    /**
     * @param mixed $preempt
     * @param mixed $args
     * @return mixed
     */
    public function filterPre($preempt, $args, $url)
    {
        if ($preempt !== false || !$this->active) {
            return $preempt;
        }
        $refusal = $this->refusal(is_array($args) ? $args : [], is_string($url) ? $url : '');
        if ($refusal === null) {
            $this->attempts++;
            return false;
        }
        if ($this->reason === null) {
            $this->reason = $refusal;
        }
        return new \WP_Error('seo_lab_review_' . $refusal, 'richiesta bloccata dalla guardia');
    }

    private function refusal(array $args, string $url): ?string
    {
        if ($this->expired()) {
            return self::REASON_TIMEOUT;
        }
        if ($this->method === null || $this->path === null || $this->attempts >= 1) {
            return self::REASON_SCOPE;
        }
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        if ($method !== $this->method) {
            return self::REASON_SCOPE;
        }
        if ($method === 'GET' && !$this->isEmptyBody($args['body'] ?? null)) {
            return self::REASON_SCOPE;
        }
        return $this->isAllowedUrl($url) ? null : self::REASON_SCOPE;
    }

    private function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== $this->host) {
            return false;
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        return ($parts['path'] ?? '') === $this->path;
    }

    /**
     * @param mixed $body
     */
    private function isEmptyBody($body): bool
    {
        return $body === null || $body === '' || $body === [];
    }

    private function clock(): int
    {
        return (int) ($this->now)();
    }
}
