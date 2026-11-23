<?php

declare(strict_types=1);

namespace SeoLab\Storage;

/**
 * Riga del registro nella tabella opzioni del sito corrente, senza autoload. SQL preparato; il nome della tabella
 * viene validato prima dell'uso. Nessuna chiamata a get_option/update_option per questa riga.
 */
final class WordPressAiLedgerStore implements AiLedgerStore
{
    public const OPTION = 'seolab_ai_ledger';

    public function read(): ?string
    {
        global $wpdb;
        $table = self::optionsTable();
        $suppress = $wpdb->suppress_errors(true);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value FROM {$table} WHERE option_name = %s",
            self::OPTION
        ), ARRAY_A);
        $error = (string) $wpdb->last_error;
        $wpdb->suppress_errors($suppress);
        if ($error !== '') {
            throw new \RuntimeException('lettura registro non riuscita');
        }
        if ($row === null) {
            return null;
        }
        if (!is_array($row) || !isset($row['option_value']) || !is_string($row['option_value'])) {
            throw new \RuntimeException('riga registro malformata');
        }
        return $row['option_value'];
    }

    public function insertIgnore(string $value): int
    {
        global $wpdb;
        $table = self::optionsTable();
        $suppress = $wpdb->suppress_errors(true);
        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::OPTION,
            $value
        ));
        $wpdb->suppress_errors($suppress);
        if ($result === false) {
            throw new \RuntimeException('inserimento registro non riuscito');
        }
        return (int) $result;
    }

    public function compareAndSwap(string $expected, string $value): int
    {
        global $wpdb;
        $table = self::optionsTable();
        $suppress = $wpdb->suppress_errors(true);
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET option_value = %s WHERE option_name = %s AND option_value = BINARY %s",
            $value,
            self::OPTION,
            $expected
        ));
        $wpdb->suppress_errors($suppress);
        if ($result === false) {
            throw new \RuntimeException('aggiornamento registro non riuscito');
        }
        return (int) $result;
    }

    /**
     * Nome della tabella opzioni dal prefisso WordPress validato: mai costruito da richieste.
     */
    private static function optionsTable(): string
    {
        global $wpdb;
        $table = (string) $wpdb->options;
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $table) !== 1) {
            throw new \RuntimeException('tabella opzioni non valida');
        }
        return $table;
    }
}
