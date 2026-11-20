<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Archivio dei report di scansione. Lo storage concreto (tabelle, retention) appartiene a S4.
 */
interface ReportArchive
{
    public function save(ScanReport $report): void;

    public function find(string $scanId): ?ScanReport;

    /**
     * @return ScanReport[]
     */
    public function forPost(int $postId): array;
}
