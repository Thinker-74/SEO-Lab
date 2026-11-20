<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Capture HTTP di un contenuto fidato. Nessun URL arbitrario come input.
 */
interface ContentCapturer
{
    public function capture(TrustedContent $content): ObservedSnapshot;
}
