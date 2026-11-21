<?php

declare(strict_types=1);

namespace SeoLab\Storage;

/**
 * Cooldown per sito tra due catture, condiviso fra utenti e azioni. Lettura e scrittura sono iniettate: in WordPress
 * sono un'opzione namespaced senza autoload. Un rifiuto non scrive nulla.
 */
final class Cooldown
{
    /** @var callable(): (int|null) */
    private $read;

    /** @var callable(int): void */
    private $write;

    /** @var int */
    private $window;

    /**
     * @param callable(): (int|null) $read
     * @param callable(int): void    $write
     */
    public function __construct(callable $read, callable $write, int $windowSeconds = 10)
    {
        if ($windowSeconds <= 0) {
            throw new \InvalidArgumentException('finestra del cooldown deve essere positiva');
        }
        $this->read = $read;
        $this->write = $write;
        $this->window = $windowSeconds;
    }

    /**
     * True se la cattura e' ammessa adesso; in quel caso registra l'istante.
     */
    public function admit(int $now): bool
    {
        $last = ($this->read)();
        if (is_int($last) && $now - $last < $this->window) {
            return false;
        }
        ($this->write)($now);
        return true;
    }
}
