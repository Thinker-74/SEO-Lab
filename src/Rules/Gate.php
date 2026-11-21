<?php

declare(strict_types=1);

namespace SeoLab\Rules;

use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\States;

/**
 * Guardie comuni alle regole P0 (P-002): capture bloccata o fallita -> not_tested; capture parziale -> inconclusive;
 * nei confronti con Yoast, atteso indisponibile o redirect con destinazione non equivalente al permalink -> inconclusive.
 */
final class Gate
{
    /**
     * Stato non positivo imposto dalla capture, oppure null per procedere.
     */
    public static function capture(ObservedSnapshot $observed): ?string
    {
        if (in_array($observed->status(), [States::SCAN_BLOCKED, States::SCAN_FAILED], true)) {
            return States::ANOMALY_NOT_TESTED;
        }
        if ($observed->status() === States::SCAN_PARTIAL) {
            return States::ANOMALY_INCONCLUSIVE;
        }
        return null;
    }

    /**
     * Guardia del confronto con l'atteso Yoast della famiglia indicata, oppure null per procedere.
     */
    public static function comparison(ObservedSnapshot $observed, ExpectedSnapshot $expected, string $family): ?string
    {
        if ($expected->resolved($family)->availability() !== States::AVAILABLE) {
            return States::ANOMALY_INCONCLUSIVE;
        }
        if (!self::redirectEquivalent($observed)) {
            return States::ANOMALY_INCONCLUSIVE;
        }
        return null;
    }

    private static function redirectEquivalent(ObservedSnapshot $observed): bool
    {
        if ($observed->redirects() === []) {
            return true;
        }
        $requested = CanonicalTargets::normalize($observed->requestedUrl());
        $final = CanonicalTargets::normalize($observed->observedUrl());
        return $requested !== null && $requested === $final;
    }

    private function __construct()
    {
    }
}
