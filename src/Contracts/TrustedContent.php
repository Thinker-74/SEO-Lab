<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Contenuto WordPress gia' risolto e fidato (ID e permalink dal core, mai input libero dell'utente).
 * Questo tipo non attesta origine ne' sicurezza del permalink: il contratto verifica solo la forma.
 * La verifica di origine, schema, host e redirect appartiene alla cattura di S1 (wp_safe_remote_get).
 */
final class TrustedContent
{
    /** @var int */
    private $postId;

    /** @var string */
    private $permalink;

    public function __construct(int $postId, string $permalink)
    {
        if ($postId <= 0) {
            throw new \InvalidArgumentException('post_id deve essere intero positivo');
        }
        if (!preg_match('#^https?://[^\s]+\z#', $permalink)) {
            throw new \InvalidArgumentException('permalink deve essere URL http(s) assoluto');
        }
        $this->postId = $postId;
        $this->permalink = $permalink;
    }

    public function postId(): int
    {
        return $this->postId;
    }

    public function permalink(): string
    {
        return $this->permalink;
    }
}
