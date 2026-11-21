<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Archivio con operazioni di sito usate dall'admin: preparazione dello schema e potatura per contenuto.
 */
interface ReportStorage extends ReportArchive
{
    /**
     * Crea o verifica lo schema del sito corrente. False se non e' possibile attestarlo.
     */
    public function ensureSchema(): bool;

    /**
     * Elimina i report scaduti e quelli oltre il cap per contenuto, conservando gli UUID indicati.
     *
     * @param string[] $mustKeepScanIds
     */
    public function prune(int $postId, array $mustKeepScanIds): void;
}
