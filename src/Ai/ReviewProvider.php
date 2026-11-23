<?php

declare(strict_types=1);

namespace SeoLab\Ai;

/**
 * Contratto di un adapter di revisione AI opzionale. P1.0 non include adapter reali: P1.1 ne istanziera' uno
 * per provider e modello validati.
 */
interface ReviewProvider
{
    public function review(ReviewRequest $request): ReviewResult;
}
