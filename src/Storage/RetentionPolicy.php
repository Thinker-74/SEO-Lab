<?php

declare(strict_types=1);

namespace SeoLab\Storage;

/**
 * Regola di potatura dei report di un contenuto, senza I/O: scadenza a 30 giorni UTC e cap di 10 report.
 * Ordine di recenza: created_at DESC, id DESC. Gli UUID da conservare entrano nel cap; la scadenza prevale su tutto.
 */
final class RetentionPolicy
{
    public const MAX_AGE_SECONDS = 2592000;
    public const MAX_PER_POST = 10;

    /**
     * @param array<int, array{id: int, scan_id: string, created: int}> $rows
     * @param string[]                                                 $mustKeepScanIds
     * @return int[] id interni da eliminare, in ordine crescente
     */
    public static function prunable(array $rows, int $now, array $mustKeepScanIds): array
    {
        $expired = [];
        $valid = [];
        foreach ($rows as $row) {
            if ($now - $row['created'] >= self::MAX_AGE_SECONDS) {
                $expired[] = $row['id'];
            } else {
                $valid[] = $row;
            }
        }

        usort($valid, static function (array $a, array $b): int {
            return [$b['created'], $b['id']] <=> [$a['created'], $a['id']];
        });

        $kept = [];
        foreach ($valid as $row) {
            if (in_array($row['scan_id'], $mustKeepScanIds, true)) {
                $kept[$row['id']] = true;
            }
        }
        foreach ($valid as $row) {
            if (count($kept) >= self::MAX_PER_POST) {
                break;
            }
            $kept[$row['id']] = true;
        }

        $delete = $expired;
        foreach ($valid as $row) {
            if (!isset($kept[$row['id']])) {
                $delete[] = $row['id'];
            }
        }
        sort($delete);
        return $delete;
    }
}
