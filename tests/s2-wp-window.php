<?php

/*
 * Finestra misurata S2 su WordPress reale (tests/s2-wordpress.sh), eseguita con "wp eval-file" nel container
 * wordpress:cli. Variabili: S2_STATE (no-yoast | missing | current | obsolete) e S2_POST_ID.
 * Per ogni stato verifica le precondizioni, poi misura una chiamata forPost() per il post di prova e una per un ID
 * inesistente. Il sensore conta le istruzioni SQL passate da wpdb (filtro "query", restituite invariate) e i contatori
 * Com_ del server; confronta digest delle tabelle yoast, delle opzioni wpseo/yoast e della riga e dei meta del post.
 * Un controllo positivo esegue una scrittura nota prima delle finestre: se il sensore non la vede, il run e' invalido.
 * Stampa una riga "S2WP {json}" con verbi SQL, contatori e digest, senza testo SQL, righe o valori di opzioni.
 */

spl_autoload_register(static function (string $class): void {
    $roots = [
        'SeoLab\\Contracts\\' => '/seolab/src/Contracts/',
        'SeoLab\\Expected\\' => '/seolab/src/Expected/',
        'SeoLab\\Rules\\' => '/seolab/src/Rules/',
    ];
    foreach ($roots as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            $file = $dir . substr($class, strlen($prefix)) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use SeoLab\Expected\YoastExpectedProvider;

const S2_MUTATING_VERBS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME',
    'OPTIMIZE', 'REPAIR', 'ANALYZE', 'LOAD', 'GRANT', 'REVOKE'];
const S2_MUTATING_COM = '/^Com_(insert|update|delete|replace|create|alter|drop|truncate|rename|optimize|repair|analyze|load|grant|revoke)/';
const S2_REPOSITORY = 'Yoast\\WP\\SEO\\Repositories\\Indexable_Repository';
const S2_VERSION_MANAGER = 'Yoast\\WP\\SEO\\Services\\Indexables\\Indexable_Version_Manager';
const S2_ABSENT_ID = 999999;

/**
 * Digest di tabelle yoast, opzioni wpseo/yoast, riga e meta del post: solo hash, nessun dato.
 *
 * @return array<string, string>
 */
function s2_digest(int $postId): array
{
    global $wpdb;
    $out = [];
    $tables = (array) $wpdb->get_col("SHOW TABLES LIKE '" . $wpdb->esc_like($wpdb->prefix) . "yoast%'");
    foreach ($tables as $name) {
        $name = (string) $name;
        $quoted = '`' . str_replace('`', '``', $name) . '`';
        $checksum = $wpdb->get_row('CHECKSUM TABLE ' . $quoted . ' EXTENDED', ARRAY_A);
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $quoted);
        $out['table:' . $name] = md5(json_encode([$count, (string) ($checksum['Checksum'] ?? '')]));
    }
    $options = (array) $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wpseo%' OR option_name LIKE 'yoast%' ORDER BY option_name",
        ARRAY_A
    );
    $out['options'] = md5(json_encode($options));
    $post = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $postId), ARRAY_A);
    $out['post_row'] = md5(json_encode($post));
    $meta = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id",
        $postId
    ), ARRAY_A);
    $out['post_meta'] = md5(json_encode($meta));
    return $out;
}

/**
 * @return array<string, int>
 */
function s2_com_counters(): array
{
    global $wpdb;
    $out = [];
    $rows = (array) $wpdb->get_results("SHOW GLOBAL STATUS LIKE 'Com\\_%'", ARRAY_A);
    foreach ($rows as $row) {
        $out[(string) $row['Variable_name']] = (int) $row['Value'];
    }
    return $out;
}

/**
 * Misura una chiamata: verbi SQL registrati dal filtro, delta Com_ non nulli, digest prima e dopo.
 *
 * @param callable(): array<string, mixed> $call
 * @return array<string, mixed>
 */
function s2_measure(int $postId, callable $call): array
{
    $digestBefore = s2_digest($postId);
    $comBefore = s2_com_counters();
    $GLOBALS['s2_statements'] = [];
    $GLOBALS['s2_recording'] = true;
    $error = null;
    $result = null;
    try {
        $result = $call();
    } catch (\Throwable $e) {
        $error = get_class($e);
    }
    $GLOBALS['s2_recording'] = false;
    $comAfter = s2_com_counters();
    $digestAfter = s2_digest($postId);

    $verbs = array_count_values($GLOBALS['s2_statements']);
    $mutatingStatements = 0;
    foreach (S2_MUTATING_VERBS as $verb) {
        $mutatingStatements += $verbs[$verb] ?? 0;
    }
    $comDelta = [];
    $mutatingCom = 0;
    foreach ($comAfter as $key => $value) {
        $delta = $value - ($comBefore[$key] ?? 0);
        if ($delta !== 0) {
            $comDelta[$key] = $delta;
        }
        if (preg_match(S2_MUTATING_COM, $key) === 1) {
            $mutatingCom += $delta;
        }
    }
    $changed = [];
    foreach (array_keys($digestBefore + $digestAfter) as $key) {
        if (($digestBefore[$key] ?? null) !== ($digestAfter[$key] ?? null)) {
            $changed[] = $key;
        }
    }
    return [
        'result' => $result,
        'error' => $error,
        'verbs' => $verbs,
        'mutating_statements' => $mutatingStatements,
        'com_nonzero_delta' => $comDelta,
        'mutating_com' => $mutatingCom,
        'changed' => $changed,
    ];
}

/**
 * Riga indexable del post tramite il repository Yoast senza auto-create: solo per precondizioni, fuori dalla finestra.
 *
 * @return object|false
 */
function s2_indexable_row(int $postId)
{
    return YoastSEO()->classes->get(S2_REPOSITORY)
        ->query()
        ->where('object_id', $postId)
        ->where('object_type', 'post')
        ->find_one();
}

/**
 * @return array<string, mixed>
 */
function s2_snapshot_summary(\SeoLab\Contracts\ExpectedSnapshot $snapshot): array
{
    $out = [];
    foreach (['canonical', 'robots', 'jsonld'] as $family) {
        $value = $snapshot->resolved($family);
        $out[$family] = ['availability' => $value->availability(), 'value' => $value->value(), 'source' => $value->source()];
    }
    return $out;
}

$state = (string) getenv('S2_STATE');
$postId = (int) getenv('S2_POST_ID');
$states = ['no-yoast', 'missing', 'current', 'obsolete'];
if (!in_array($state, $states, true) || $postId <= 0) {
    fwrite(STDERR, "S2_STATE o S2_POST_ID non validi\n");
    exit(2);
}

global $wpdb;
$table = $wpdb->prefix . 'yoast_indexable';

$GLOBALS['s2_recording'] = false;
$GLOBALS['s2_statements'] = [];
add_filter('query', static function ($query) {
    if (!empty($GLOBALS['s2_recording'])) {
        $GLOBALS['s2_statements'][] = strtoupper((string) strtok(ltrim((string) $query), " \t\r\n("));
    }
    return $query;
}, 10, 1);

$tableExists = $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($table) . "'") === $table;
$rowsFor = static function (int $id) use ($wpdb, $table, $tableExists): int {
    if (!$tableExists) {
        return 0;
    }
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE object_id = %d AND object_type = %s",
        $id,
        'post'
    ));
};

// Precondizioni: se non valgono, la finestra non e' interpretabile e il run si ferma.
$pre = ['state' => $state, 'table_exists' => $tableExists, 'rows_post' => $rowsFor($postId), 'rows_absent_id' => $rowsFor(S2_ABSENT_ID)];
$pre['absent_id_row_count_zero'] = $pre['rows_absent_id'] === 0;
$pre['yoast_present'] = function_exists('YoastSEO');
$pre['ok'] = false;
if ($state === 'no-yoast') {
    $pre['ok'] = !$pre['yoast_present'];
} elseif ($pre['yoast_present']) {
    $row = s2_indexable_row($postId);
    $manager = YoastSEO()->classes->get(S2_VERSION_MANAGER);
    $pre['indexable_present'] = is_object($row);
    $pre['needs_upgrade'] = is_object($row) ? $manager->indexable_needs_upgrade($row) : null;
    if ($state === 'missing') {
        $pre['ok'] = $pre['rows_post'] === 0;
    } elseif ($state === 'current') {
        $pre['ok'] = $pre['rows_post'] === 1 && is_object($row) && $pre['needs_upgrade'] === false;
    } else {
        $pre['ok'] = $pre['rows_post'] === 1 && is_object($row) && $pre['needs_upgrade'] === true;
    }
}
$pre['ok'] = $pre['ok'] && $pre['absent_id_row_count_zero'];

if (!$pre['ok']) {
    echo 'S2WP ' . json_encode(['phase' => 'window-' . $state, 'preconditions' => $pre], JSON_UNESCAPED_SLASHES) . "\n";
    exit(3);
}

// Controllo positivo: una UPDATE a zero righe e un'opzione wpseo temporanea. Il sensore deve vederle entrambe.
$control = s2_measure($postId, static function () use ($wpdb, $table): array {
    $wpdb->query("UPDATE {$table} SET version = version WHERE 1 = 0");
    add_option('wpseo_s2_positive_control', 'control', '', 'no');
    return [];
});
delete_option('wpseo_s2_positive_control');

$provider = new YoastExpectedProvider();
$windows = [];
$windows['post'] = s2_measure($postId, static function () use ($provider, $postId): array {
    $snapshot = $provider->forPost($postId);
    return ['reason' => $provider->lastReason(), 'jsonld_reason' => $provider->lastJsonLdReason()] + s2_snapshot_summary($snapshot);
});
$windows['missing_id'] = s2_measure($postId, static function () use ($provider): array {
    $snapshot = $provider->forPost(S2_ABSENT_ID);
    return ['reason' => $provider->lastReason(), 'jsonld_reason' => $provider->lastJsonLdReason()] + s2_snapshot_summary($snapshot);
});

$report = [
    'phase' => 'window-' . $state,
    'wp_version' => get_bloginfo('version'),
    'php_version' => PHP_VERSION,
    'yoast_version' => defined('WPSEO_VERSION') ? WPSEO_VERSION : null,
    'is_admin' => is_admin(),
    'permalink' => get_permalink($postId),
    'post_id' => $postId,
    'preconditions' => $pre,
    'control' => [
        'verbs' => $control['verbs'],
        'mutating_statements' => $control['mutating_statements'],
        'mutating_com' => $control['mutating_com'],
        'changed' => $control['changed'],
    ],
    'windows' => $windows,
];
echo 'S2WP ' . json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
