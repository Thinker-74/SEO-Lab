<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Enumerazioni P0 (specifica par. 5). Stati separati: anomalia, causa, scansione, severita', disponibilita' e diff.
 * Nessun campo percentuale.
 */
final class States
{
    public const ANOMALY_CONFIRMED = 'confirmed_anomaly';
    public const ANOMALY_NONE = 'no_anomaly';
    public const ANOMALY_INCONCLUSIVE = 'inconclusive';
    public const ANOMALY_NOT_TESTED = 'not_tested';
    public const ANOMALY_STATES = [
        self::ANOMALY_CONFIRMED,
        self::ANOMALY_NONE,
        self::ANOMALY_INCONCLUSIVE,
        self::ANOMALY_NOT_TESTED,
    ];

    public const CAUSE_CONFIRMED = 'confirmed';
    public const CAUSE_LIKELY = 'likely';
    public const CAUSE_POSSIBLE = 'possible';
    public const CAUSE_UNKNOWN = 'unknown';
    public const CAUSE_STATES = [
        self::CAUSE_CONFIRMED,
        self::CAUSE_LIKELY,
        self::CAUSE_POSSIBLE,
        self::CAUSE_UNKNOWN,
    ];

    public const SCAN_OK = 'ok';
    public const SCAN_PARTIAL = 'partial';
    public const SCAN_BLOCKED = 'blocked';
    public const SCAN_FAILED = 'failed';
    public const SCAN_STATES = [
        self::SCAN_OK,
        self::SCAN_PARTIAL,
        self::SCAN_BLOCKED,
        self::SCAN_FAILED,
    ];

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_STATES = [
        self::SEVERITY_INFO,
        self::SEVERITY_WARNING,
        self::SEVERITY_ERROR,
    ];

    public const DIFF_RESOLVED = 'resolved';
    public const DIFF_PERSISTS = 'persists';
    public const DIFF_CHANGED = 'changed';
    public const DIFF_INCONCLUSIVE = 'inconclusive';
    public const DIFF_STATES = [
        self::DIFF_RESOLVED,
        self::DIFF_PERSISTS,
        self::DIFF_CHANGED,
        self::DIFF_INCONCLUSIVE,
    ];

    public const AVAILABLE = 'available';
    public const UNAVAILABLE = 'unavailable';
    public const AVAILABILITY_STATES = [
        self::AVAILABLE,
        self::UNAVAILABLE,
    ];

    public const OBSERVED_PRESENT = 'present';
    public const OBSERVED_ABSENT = 'absent';
    public const OBSERVED_UNOBSERVABLE = 'unobservable';
    public const OBSERVED_AVAILABILITY_STATES = [
        self::OBSERVED_PRESENT,
        self::OBSERVED_ABSENT,
        self::OBSERVED_UNOBSERVABLE,
    ];

    /**
     * @param mixed    $value
     * @param string[] $allowed
     */
    public static function assertIn(string $field, $value, array $allowed): void
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException($field . ' fuori enum: ' . json_encode($value));
        }
    }

    private function __construct()
    {
    }
}
