<?php

declare(strict_types=1);

namespace SeoLab\Storage;

use SeoLab\Contracts\ReportStorage;
use SeoLab\Contracts\ScanReport;

/**
 * Archivio dei report nella tabella {prefisso}seolab_reports del sito corrente. Ogni insert e' atomico e non c'e' mai
 * upsert: un UUID gia' usato fallisce. Le letture filtrano subito la scadenza; le scritture di plugin sono solo la
 * tabella e l'opzione di schema. Non usa WP-Cron.
 */
final class WordPressReportArchive implements ReportStorage
{
    public const SCHEMA_OPTION = 'seolab_schema_version';
    public const SCHEMA_VERSION = '1';
    private const TABLE_SUFFIX = 'seolab_reports';
    private const MAX_JSON_BYTES = 1048576;
    private const PRUNE_BATCH = 200;

    /**
     * Nome della tabella dal prefisso WordPress validato: mai costruito da richieste.
     */
    public static function tableName(): string
    {
        global $wpdb;
        $prefix = (string) $wpdb->prefix;
        if (preg_match('/^[A-Za-z0-9_]{1,40}\z/', $prefix) !== 1) {
            throw new \RuntimeException('prefisso tabelle non valido');
        }
        return $prefix . self::TABLE_SUFFIX;
    }

    public function ensureSchema(): bool
    {
        global $wpdb;
        if ((string) get_option(self::SCHEMA_OPTION, '') === self::SCHEMA_VERSION && self::tableExists()) {
            return true;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::tableName();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_id char(36) NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            report longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scan_id (scan_id),
            KEY created_at (created_at),
            KEY post_created (post_id, created_at, id)
        ) {$charset};");
        if (!self::tableExists()) {
            return false;
        }
        if (!add_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, '', 'no')) {
            update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, 'no');
        }
        return true;
    }

    public function save(ScanReport $report): void
    {
        global $wpdb;
        $json = json_encode($report->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > self::MAX_JSON_BYTES) {
            throw new \RuntimeException('report non archiviabile');
        }
        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert(self::tableName(), [
            'scan_id' => $report->scanId(),
            'post_id' => $report->postId(),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'report' => $json,
        ], ['%s', '%d', '%s', '%s']);
        $wpdb->suppress_errors($suppress);
        if ($inserted !== 1) {
            throw new \RuntimeException('scrittura non riuscita');
        }
    }

    public function find(string $scanId): ?ScanReport
    {
        global $wpdb;
        $table = self::tableName();
        $suppress = $wpdb->suppress_errors(true);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT report FROM {$table} WHERE scan_id = %s AND created_at > %s",
            $scanId,
            self::cutoff()
        ), ARRAY_A);
        $wpdb->suppress_errors($suppress);
        if (!is_array($row) || !isset($row['report'])) {
            return null;
        }
        return self::decode((string) $row['report']);
    }

    public function forPost(int $postId): array
    {
        global $wpdb;
        $table = self::tableName();
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT report FROM {$table} WHERE post_id = %d AND created_at > %s ORDER BY created_at DESC, id DESC",
            $postId,
            self::cutoff()
        ));
        $wpdb->suppress_errors($suppress);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $json) {
            $report = self::decode((string) $json);
            if ($report !== null) {
                $out[] = $report;
            }
        }
        return $out;
    }

    /**
     * Elimina gli scaduti (a batch) e, per il contenuto, quelli oltre il cap conservando gli UUID indicati.
     *
     * @param string[] $mustKeepScanIds
     */
    public function prune(int $postId, array $mustKeepScanIds): void
    {
        global $wpdb;
        $table = self::tableName();
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at <= %s LIMIT " . self::PRUNE_BATCH,
            self::cutoff()
        ));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, scan_id, created_at FROM {$table} WHERE post_id = %d",
            $postId
        ), ARRAY_A);
        $wpdb->suppress_errors($suppress);

        $candidates = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $candidates[] = [
                'id' => (int) $row['id'],
                'scan_id' => (string) $row['scan_id'],
                'created' => (int) strtotime($row['created_at'] . ' UTC'),
            ];
        }
        $ids = RetentionPolicy::prunable($candidates, time(), $mustKeepScanIds);
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids));
        $wpdb->suppress_errors($suppress);
    }

    private static function tableExists(): bool
    {
        global $wpdb;
        $table = self::tableName();
        $suppress = $wpdb->suppress_errors(true);
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $wpdb->suppress_errors($suppress);
        return is_string($found) && $found === $table;
    }

    private static function cutoff(): string
    {
        return gmdate('Y-m-d H:i:s', time() - RetentionPolicy::MAX_AGE_SECONDS);
    }

    /**
     * Chiave corrotta, schema sconosciuto o JSON non valido: nessun report, nessun messaggio tecnico.
     */
    private static function decode(string $json): ?ScanReport
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        try {
            return new ScanReport($data);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
