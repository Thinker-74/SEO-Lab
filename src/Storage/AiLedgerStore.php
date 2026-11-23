<?php

declare(strict_types=1);

namespace SeoLab\Storage;

/**
 * Accesso alla sola riga del registro AI del sito. Le implementazioni non usano cache: la riga del database e'
 * l'unica fonte. Un errore di lettura o di scrittura e' un'eccezione, mai un valore vuoto.
 */
interface AiLedgerStore
{
    /**
     * @return string|null valore grezzo della riga, null se la riga non esiste
     * @throws \RuntimeException su errore del database
     */
    public function read(): ?string;

    /**
     * Inserimento che non sovrascrive: 1 se la riga e' creata, 0 se esiste gia'.
     *
     * @throws \RuntimeException su errore del database
     */
    public function insertIgnore(string $value): int;

    /**
     * Aggiornamento condizionato al valore precedente, confronto binario: 1 se applicato, 0 in caso di conflitto.
     *
     * @throws \RuntimeException su errore del database
     */
    public function compareAndSwap(string $expected, string $value): int;
}
