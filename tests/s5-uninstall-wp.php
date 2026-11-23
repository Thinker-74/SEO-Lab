<?php

/*
 * Gate P-005 su WordPress reale, eseguito con "wp eval-file" dal container wordpress:cli (tests/s4-wordpress.sh
 * con --release-zip). S5_STEP sceglie il passo; S5_TABLE_BLOGS e S5_NO_TABLE_BLOGS elencano gli ID di blog
 * che devono avere la tabella dei report (gli altri blog ne sono privi). Ogni controllo stampa una riga "S5WP {json}" con check,
 * ok e detail, senza contenuti. Il passo "uninstall" chiama uninstall_plugin() come fa WordPress, nel contesto
 * del blog selezionato con --url.
 */

global $wpdb;

function s5_check(string $name, bool $ok, string $detail = ''): void
{
    echo 'S5WP ' . json_encode(['check' => $name, 'ok' => $ok, 'detail' => $detail], JSON_UNESCAPED_SLASHES) . "\n";
}

function s5_ids(string $env): array
{
    return array_values(array_filter(array_map('intval', preg_split('/\s+/', trim((string) getenv($env))) ?: [])));
}

function s5_blog_ids(): array
{
    global $wpdb;
    return array_map('intval', $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs} ORDER BY blog_id ASC"));
}

/** Esegue $fn nel contesto di ogni blog e ripristina sempre il contesto precedente. */
function s5_each_blog(callable $fn): array
{
    $result = [];
    foreach (s5_blog_ids() as $blogId) {
        switch_to_blog($blogId);
        try {
            $result[$blogId] = $fn($blogId);
        } finally {
            restore_current_blog();
        }
    }
    return $result;
}

function s5_table_exists(string $name): bool
{
    global $wpdb;
    $table = $wpdb->prefix . $name;
    $suppress = $wpdb->suppress_errors(true);
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    $wpdb->suppress_errors($suppress);
    return $found === $table;
}

function s5_option_present(string $name): bool
{
    return get_option($name, null) !== null;
}

$step = (string) getenv('S5_STEP');
$tableBlogs = s5_ids('S5_TABLE_BLOGS');
$startBlog = get_current_blog_id();

switch ($step) {
    case 'seed':
        // Sentinelle di un altro componente su ogni blog: tabella e opzione che l'uninstall deve lasciare intatte.
        $seeded = s5_each_blog(static function (int $blogId): bool {
            global $wpdb;
            $suppress = $wpdb->suppress_errors(true);
            $created = $wpdb->query('CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . 's5_other_sentinel (id INT NOT NULL PRIMARY KEY)');
            $inserted = $wpdb->query('INSERT IGNORE INTO ' . $wpdb->prefix . 's5_other_sentinel (id) VALUES (1)');
            $wpdb->suppress_errors($suppress);
            update_option('s5_other_component', 'keep', false);
            // Opzioni della revisione AI P1.2: l'uninstall le rimuove solo con il drop riuscito, come le opzioni P0.
            update_option('seolab_ai_enabled', '1', false);
            update_option('seolab_ai_ledger', '{"v":1,"rows":[]}', false);
            // Cooldown P0 presente anche senza scansioni (modalita' --p1-ai non esegue scansioni P0).
            if (!s5_option_present('seolab_scan_last')) {
                update_option('seolab_scan_last', (string) time(), false);
            }
            return $created !== false && $inserted !== false && s5_option_present('s5_other_component')
                && s5_option_present('seolab_ai_enabled') && s5_option_present('seolab_ai_ledger');
        });
        s5_check('sentinelle_altro_componente_seminate', !in_array(false, $seeded, true), implode(',', array_keys($seeded)));
        if (!getenv('S5_SKIP_YOAST')) {
            s5_check('precondizione_sentinella_yoast', s5_option_present('wpseo_titles'), 'wpseo_titles sul blog corrente');
        }
        break;

    case 'kept':
    case 'removed':
        $expectSeolab = $step === 'kept';
        $state = s5_each_blog(static function (): array {
            return [
                'table' => s5_table_exists('seolab_reports'),
                'schema' => s5_option_present('seolab_schema_version'),
                'last' => s5_option_present('seolab_scan_last'),
                'ai_enabled' => s5_option_present('seolab_ai_enabled'),
                'ai_ledger' => s5_option_present('seolab_ai_ledger'),
                'sentinel_table' => s5_table_exists('s5_other_sentinel'),
                'sentinel_option' => s5_option_present('s5_other_component'),
                'sentinel_wp' => s5_option_present('blogname'),
            ];
        });
        foreach ($state as $blogId => $s) {
            $hasTable = in_array($blogId, $tableBlogs, true);
            $seolabOk = $expectSeolab
                ? ($s['table'] === $hasTable && (!$hasTable || ($s['schema'] && $s['last'])))
                : ($s['table'] === false && $s['schema'] === false && $s['last'] === false);
            s5_check("seolab_{$step}_blog_$blogId", $seolabOk, 'tabella=' . ($s['table'] ? 'si' : 'no') . ' opzioni=' . ($s['schema'] ? 'si' : 'no') . '/' . ($s['last'] ? 'si' : 'no'));
            $aiOk = $expectSeolab
                ? ($s['ai_enabled'] && $s['ai_ledger'])
                : (!$s['ai_enabled'] && !$s['ai_ledger']);
            s5_check("ai_{$step}_blog_$blogId", $aiOk, 'opzioni AI=' . ($s['ai_enabled'] ? 'si' : 'no') . '/' . ($s['ai_ledger'] ? 'si' : 'no'));
            s5_check("sentinelle_intatte_blog_$blogId", $s['sentinel_table'] && $s['sentinel_option'] && $s['sentinel_wp'], 'altro componente e WordPress');
        }
        if (!getenv('S5_SKIP_YOAST')) {
            $yoastOnFirst = in_array(1, array_keys($state), true);
            s5_check("sentinella_yoast_$step", $yoastOnFirst && s5_option_present('wpseo_titles'), 'wpseo_titles sul blog 1');
        }
        if ($step === 'removed') {
            s5_check('prefisso_blog_ripristinato', $wpdb->prefix === $wpdb->get_blog_prefix($startBlog), (string) $wpdb->prefix);
            s5_check('blog_corrente_ripristinato', get_current_blog_id() === $startBlog, 'blog ' . get_current_blog_id());
        }
        break;

    case 'refuse_seed':
        // Solo nel test del rifiuto: ricrea la tabella e le opzioni SEO Lab sui blog che la devono avere.
        foreach ($tableBlogs as $blogId) {
            switch_to_blog($blogId);
            try {
                $wpdb->query('CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . 'seolab_reports (id BIGINT UNSIGNED NOT NULL PRIMARY KEY)');
                update_option('seolab_schema_version', '1', false);
                update_option('seolab_scan_last', (string) time(), false);
            } finally {
                restore_current_blog();
            }
        }
        // Opzioni AI su ogni blog: il rifiuto del DROP non deve rimuoverle.
        s5_each_blog(static function (): void {
            update_option('seolab_ai_enabled', '1', false);
            update_option('seolab_ai_ledger', '{"v":1,"rows":[]}', false);
        });
        s5_check('tabelle_rifiuto_ricreate', true, implode(',', $tableBlogs));
        break;

    case 'uninstall':
    case 'uninstall_refused':
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if ($step === 'uninstall_refused') {
            // Simula un rifiuto del DROP: solo in questo processo di test, nessuna modifica al codice distribuito.
            add_filter('query', static function ($query) {
                return stripos((string) $query, 'DROP TABLE') !== false
                    ? 'SELECT * FROM s5_drop_refused_missing_table'
                    : $query;
            });
        }
        $before = get_current_blog_id();
        $result = uninstall_plugin('seo-lab/seo-lab.php');
        s5_check('uninstall_eseguito', !is_wp_error($result), is_wp_error($result) ? $result->get_error_code() : 'ok');
        s5_check('uninstall_blog_corrente_ripristinato', get_current_blog_id() === $before);
        break;

    default:
        s5_check('passo_valido', false, 'S5_STEP=' . $step);
}
