<?php

/*
 * Sonda S4 su WordPress reale, eseguita con "wp eval-file" dal container wordpress:cli (tests/s4-wordpress.sh).
 * Usa il plugin gia' attivo e l'archivio del sito selezionato con --url. Ogni controllo stampa una riga
 * "S4WP {json}" con check, ok e detail senza dati di contenuto. Variabili: S4_POST_ID (contenuto del sito),
 * S4_OTHER_UUID (UUID di un report dell'altro sito, se presente), S4_OTHER_PREFIX (prefisso tabelle dell'altro sito).
 */

global $wpdb;

use SeoLab\Contracts\ScanReport;
use SeoLab\Storage\RetentionPolicy;
use SeoLab\Storage\WordPressReportArchive;

function s4_check(string $name, bool $ok, string $detail = ''): void
{
    echo 'S4WP ' . json_encode(['check' => $name, 'ok' => $ok, 'detail' => $detail], JSON_UNESCAPED_SLASHES) . "\n";
}

/** Riscrive un report esistente con un nuovo UUID: stesso contenuto, identita' distinta. */
function s4_clone(ScanReport $source, string $scanId): ScanReport
{
    $data = $source->toArray();
    $data['scan_id'] = $scanId;
    return new ScanReport($data);
}

function s4_uuid(int $n): string
{
    return sprintf('00000000-0000-4000-9000-%012d', $n);
}

$postId = (int) getenv('S4_POST_ID');
$archive = new WordPressReportArchive();
$table = WordPressReportArchive::tableName();
$suppress = $wpdb->suppress_errors(true);

// 1. Provisioning idempotente e versione attestata.
$first = $archive->ensureSchema();
$second = $archive->ensureSchema();
$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
s4_check('provisioning_idempotente', $first && $second && $exists, $table);

$autoload = $wpdb->get_var($wpdb->prepare(
    "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
    WordPressReportArchive::SCHEMA_OPTION
));
s4_check('opzione_schema_senza_autoload', in_array($autoload, ['no', 'off'], true), 'autoload=' . (string) $autoload);

$prefixOk = $table === $wpdb->prefix . 'seolab_reports';
s4_check('tabella_dal_prefisso_del_sito', $prefixOk, $table);

// 2. Contenuto di base: un report reale dal flusso HTTP del sito.
$base = $wpdb->get_var($wpdb->prepare("SELECT report FROM {$table} WHERE post_id = %d ORDER BY id ASC LIMIT 1", $postId));
$source = is_string($base) ? new ScanReport(json_decode($base, true)) : null;
s4_check('report_base_presente', $source !== null, 'post ' . $postId);

if ($source !== null) {
    // 3. Retention senza WP-Cron: 11 report recenti, uno a 31 giorni, uno a 30 giorni esatti.
    $recent = [];
    for ($i = 1; $i <= 11; $i++) {
        $clone = s4_clone($source, s4_uuid(100 + $i));
        $archive->save($clone);
        $recent[] = $clone->scanId();
    }
    $wpdb->query($wpdb->prepare("UPDATE {$table} SET created_at = %s WHERE scan_id = %s", gmdate('Y-m-d H:i:s', time() - 31 * 86400), s4_uuid(101)));
    $wpdb->query($wpdb->prepare("UPDATE {$table} SET created_at = %s WHERE scan_id = %s", gmdate('Y-m-d H:i:s', time() - 30 * 86400), s4_uuid(102)));
    $archive->prune($postId, [$recent[10]]);
    $expiredLeft = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND created_at <= %s",
        $postId,
        gmdate('Y-m-d H:i:s', time() - RetentionPolicy::MAX_AGE_SECONDS)
    ));
    $validCount = count($archive->forPost($postId));
    s4_check('scadenza_30_giorni_eliminata', $expiredLeft === 0, 'scaduti residui=' . $expiredLeft);
    s4_check('cap_10_per_contenuto', $validCount <= 10, 'validi=' . $validCount);
    s4_check('scansione_normale_conserva_il_piu_recente', $archive->find($recent[10]) !== null);

    // 4. Verify sul decimo: base = il piu' vecchio dei validi, nuovo report, potatura con entrambi.
    $valid = $archive->forPost($postId);
    $baseOfVerify = end($valid);
    $baseId = $baseOfVerify instanceof ScanReport ? $baseOfVerify->scanId() : '';
    $newer = s4_clone($source, s4_uuid(900));
    $archive->save($newer);
    $archive->prune($postId, [$newer->scanId(), $baseId]);
    $afterVerify = $archive->forPost($postId);
    $ids = array_map(static function (ScanReport $r): string {
        return $r->scanId();
    }, $afterVerify);
    s4_check('verify_preserva_base_e_nuovo', in_array($baseId, $ids, true) && in_array($newer->scanId(), $ids, true),
        'base=' . $baseId);
    s4_check('verify_totale_non_superiore_a_10', count($afterVerify) <= 10, 'totale=' . count($afterVerify));

    // 5. UUID duplicato: nessun upsert, nessuna riga in piu'.
    $before = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $postId));
    $duplicateFailed = false;
    try {
        $archive->save($newer);
    } catch (\RuntimeException $e) {
        $duplicateFailed = true;
    }
    $after = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $postId));
    s4_check('uuid_duplicato_rifiutato_senza_upsert', $duplicateFailed && $before === $after, "prima={$before} dopo={$after}");

    // 6. Chiave corrotta e schema sconosciuto: esclusi dalle letture, nessun dettaglio tecnico.
    $corruptId = s4_uuid(950);
    $unknownId = s4_uuid(951);
    $wpdb->insert($table, ['scan_id' => $corruptId, 'post_id' => $postId, 'created_at' => gmdate('Y-m-d H:i:s'), 'report' => '{bad'], ['%s', '%d', '%s', '%s']);
    $unknown = $source->toArray();
    $unknown['scan_id'] = $unknownId;
    $unknown['schema_version'] = '2';
    $wpdb->insert($table, ['scan_id' => $unknownId, 'post_id' => $postId, 'created_at' => gmdate('Y-m-d H:i:s'), 'report' => (string) json_encode($unknown)], ['%s', '%d', '%s', '%s']);
    $ids = array_map(static function (ScanReport $r): string {
        return $r->scanId();
    }, $archive->forPost($postId));
    s4_check('chiavi_corrotte_e_schema_sconosciuto_esclusi', !in_array($corruptId, $ids, true) && !in_array($unknownId, $ids, true)
        && $archive->find($corruptId) === null && $archive->find($unknownId) === null);
    $wpdb->delete($table, ['scan_id' => $corruptId], ['%s']);
    $wpdb->delete($table, ['scan_id' => $unknownId], ['%s']);

    // 7. Errore di scrittura: la tabella non c'e' per un istante; nessun report apparentemente archiviato.
    $probeId = s4_uuid(960);
    $probe = s4_clone($source, $probeId);
    $wpdb->query("RENAME TABLE `{$table}` TO `{$table}_s4x`");
    $writeFailed = false;
    try {
        $archive->save($probe);
    } catch (\RuntimeException $e) {
        $writeFailed = true;
    }
    $wpdb->query("RENAME TABLE `{$table}_s4x` TO `{$table}`");
    s4_check('errore_scrittura_non_archiviato', $writeFailed && $archive->find($probeId) === null);

    // 7b. Tetto di 1 MiB: un valore atteso di 1,1 MB (nessun limite di lunghezza nel contratto) rende il report
    // valido ma oltre il limite serializzato. Il report base e' una cattura bloccata: il JSON-LD non e' ammesso.
    $rowsTotal = static function () use ($wpdb, $table): int {
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    };
    $capId = s4_uuid(970);
    $capData = $source->toArray();
    $capData['scan_id'] = $capId;
    $capData['expected']['resolved']['robots'] = [
        'availability' => 'available',
        'value' => str_repeat('a', 1100000),
        'source' => 'seo-lab-cap-test',
    ];
    try {
        $capReport = new ScanReport($capData);
    } catch (\Throwable $e) {
        s4_check('tetto_1_mib_report_valido', false, get_class($e) . ': ' . $e->getMessage());
        $capReport = null;
    }
    $rowsBeforeCap = $rowsTotal();
    $capRejected = false;
    if ($capReport !== null) {
        try {
            $archive->save($capReport);
        } catch (\RuntimeException $e) {
            $capRejected = true;
        }
        s4_check('tetto_1_mib_rifiuta_senza_insert', $capRejected && $archive->find($capId) === null && $rowsTotal() === $rowsBeforeCap,
            'json=' . strlen((string) json_encode($capReport->toArray())));
    }

    // 7c. Opzione di schema cancellata con tabella esistente: il provisioning riesegue dbDelta senza perdere righe.
    $rowsBeforeProv = $rowsTotal();
    delete_option(WordPressReportArchive::SCHEMA_OPTION);
    $reprovisioned = $archive->ensureSchema();
    $optionBack = (string) get_option(WordPressReportArchive::SCHEMA_OPTION, '') === WordPressReportArchive::SCHEMA_VERSION;
    s4_check('provisioning_opzione_cancellata_senza_perdita_righe', $reprovisioned && $optionBack && $rowsTotal() === $rowsBeforeProv,
        "prima={$rowsBeforeProv} dopo=" . $rowsTotal());

    // 8. Isolamento: il report di questo sito non e' visibile dal blog di confronto (stesso post ID).
    $other = getenv('S4_OTHER_BLOG');
    if (is_string($other) && $other !== '' && (int) $other !== get_current_blog_id()) {
        switch_to_blog((int) $other);
        $foreign = (new WordPressReportArchive())->find($newer->scanId());
        restore_current_blog();
        s4_check('report_altro_sito_invisibile', $foreign === null, 'blog ' . $other);
    }
}

// 9. Catalogo italiano: i titoli delle 8 regole in it_IT sono quelli del .po, non i msgid inglesi.
$expectedIt = [
    'CANONICAL_MULTIPLE_DISTINCT' => 'Più di un URL canonical distinto',
    'CANONICAL_EXPECTED_MISMATCH' => 'Il canonical differisce dal valore atteso',
    'CANONICAL_MISSING_WHEN_EXPECTED' => 'Canonical assente dove Yoast ne prevede uno',
    'ROBOTS_EFFECTIVE_MISMATCH' => 'Le direttive robots effettive differiscono dal valore atteso',
    'ROBOTS_UNEXPECTED_NOINDEX' => 'Noindex inatteso',
    'JSONLD_PARSE_ERROR' => 'Blocco JSON-LD non interpretabile',
    'JSONLD_ENTITY_CONTRADICTION' => 'Un\'entità JSON-LD contraddice quella attesa',
    'JSONLD_EXPECTED_NODE_MISSING' => 'Nodo JSON-LD atteso mancante',
];
// Il dominio del plugin e' caricato con il .mo italiano: il caricamento in init segue la lingua della richiesta,
// qui la stessa lettura viene fatta esplicitamente sul file del plugin.
unload_textdomain('seo-lab');
load_textdomain('seo-lab', WP_PLUGIN_DIR . '/seo-lab/languages/seo-lab-it_IT.mo', 'it_IT');
$gotIt = [];
foreach (array_keys($expectedIt) as $ruleId) {
    $gotIt[$ruleId] = (string) (\SeoLab\Admin\RuleMessages::forRule($ruleId)['title'] ?? '');
}
$mismatch = array_keys(array_filter($gotIt, static function (string $title, string $ruleId) use ($expectedIt): bool {
    return $title !== $expectedIt[$ruleId];
}, ARRAY_FILTER_USE_BOTH));
s4_check('catalogo_it_titoli_regole', $mismatch === [], 'regole diverse: ' . implode(',', $mismatch));

// 10. Opzione del cooldown senza autoload quando presente.
$cool = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'seolab_scan_last'));
s4_check('cooldown_senza_autoload', $cool === null || in_array($cool, ['no', 'off'], true), 'autoload=' . (string) $cool);

$wpdb->suppress_errors($suppress);
