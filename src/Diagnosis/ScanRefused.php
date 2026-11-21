<?php

declare(strict_types=1);

namespace SeoLab\Diagnosis;

/**
 * Scansione rifiutata prima di qualunque cattura o risoluzione Yoast. Il motivo e' un codice stabile.
 */
final class ScanRefused extends \RuntimeException
{
    public const NOT_ELIGIBLE = 'not_eligible';
    public const RATE_LIMITED = 'rate_limited';

    /** @var string */
    private $reason;

    public function __construct(string $reason)
    {
        parent::__construct($reason);
        $this->reason = $reason;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
