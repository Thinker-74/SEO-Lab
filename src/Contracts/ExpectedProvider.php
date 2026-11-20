<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Provider dell'atteso (es. Yoast) dal solo ID WordPress. Ritorna sempre uno snapshot: l'indisponibilita' e' dichiarata dentro lo snapshot.
 */
interface ExpectedProvider
{
    public function forPost(int $postId): ExpectedSnapshot;
}
