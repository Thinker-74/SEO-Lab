<?php

/*
 * Finestra S4 sulle scritture (WordPress reale, "wp --context=admin eval-file" da tests/s4-wordpress.sh).
 * Esegue in-process Scan e Verify con Plugin::actions() mentre Yoast e' attivo e l'indexable del contenuto e' assente.
 * Il sensore conta le istruzioni mutanti passate da wpdb (filtro "query", invariate). Ammesse solo scritture verso
 * la tabella *_seolab_reports o verso opzioni con nome seolab_*. Nessun testo SQL, valore o riga viene stampato:
 * solo verbo e tabella. Controllo positivo: una scrittura nota (seolab_*) prima della finestra deve essere vista.
 * Variabili: S4_POST_ID (contenuto), S4_ADMIN_LOGIN (admin del sito corrente).
 */

global $wpdb;

function s4_win(string $name, bool $ok, string $detail = ''): void
{
    echo 'S4WP ' . json_encode(['check' => $name, 'ok' => $ok, 'detail' => $detail], JSON_UNESCAPED_SLASHES) . "\n";
}

const S4_MUTATING = '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|RENAME|OPTIMIZE|REPAIR|ANALYZE|LOAD|GRANT|REVOKE)\b/i';

$postId = (int) getenv('S4_POST_ID');
$login = (string) getenv('S4_ADMIN_LOGIN');
$user = get_user_by('login', $login);
s4_win('admin_del_sito_presente', $user instanceof WP_User);
if (!$user instanceof WP_User) {
    exit(1);
}
wp_set_current_user($user->ID);

$captured = [];
add_filter('query', static function ($query) use (&$captured) {
    if (is_string($query) && preg_match(S4_MUTATING, $query) === 1) {
        $captured[] = $query;
    }
    return $query;
});

// Controllo positivo: il sensore deve vedere una scrittura nota su opzione seolab_*.
$wpdb->query("UPDATE {$wpdb->options} SET option_value = option_value WHERE option_name = 'seolab_schema_version'");
$seen = count($captured);
$captured = [];
s4_win('controllo_positivo_sensore', $seen >= 1, 'statement visti=' . $seen);

// Precondizioni: Yoast attivo, indexable del contenuto assente (prima di yoast index).
s4_win('precondizione_yoast_attivo', defined('WPSEO_VERSION'), defined('WPSEO_VERSION') ? (string) WPSEO_VERSION : 'assente');
$indexable = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}yoast_indexable WHERE object_id = %d AND object_type = 'post'",
    $postId
));
s4_win('precondizione_indexable_assente', $indexable === 0, 'righe=' . $indexable);

/** True se lo statement e' ammesso: tabella report del sito o opzione seolab_*. */
function s4_allowed(string $sql): bool
{
    global $wpdb;
    if (preg_match('/\b' . preg_quote($wpdb->prefix, '/') . 'seolab_reports\b/', $sql) === 1) {
        return true;
    }
    return preg_match('/\b' . preg_quote($wpdb->options, '/') . '\b/', $sql) === 1
        && preg_match("/'seolab_[a-z_]+'/", $sql) === 1;
}

/** Verbo e tabella di uno statement, senza valori. */
function s4_shape(string $sql): string
{
    if (preg_match('/^\s*(\w+)/', $sql, $verb) !== 1) {
        return 'sconosciuto';
    }
    if (preg_match('/(?:INTO|UPDATE|FROM|TABLE)\s+`?(\w+)`?/i', $sql, $table) !== 1) {
        return strtoupper($verb[1]) . ' ?';
    }
    return strtoupper($verb[1]) . ' ' . $table[1];
}

$actions = \SeoLab\Admin\Plugin::actions();

// Cooldown azzerato fuori dalla finestra (scrittura su opzione seolab_, prima del reset del sensore).
delete_option('seolab_scan_last');
$captured = [];
$nonce = wp_create_nonce('seo_lab_scan_' . get_current_blog_id());
$scan = $actions->scan('POST', ['post_id' => (string) $postId, 'seo_lab_nonce' => $nonce]);
$scanWrites = $captured;
s4_win('scan_ok', ($scan['ok'] ?? false) === true, (string) ($scan['error'] ?? ''));
s4_win('scan_scritture_solo_seolab', array_reduce($scanWrites, static function (bool $acc, string $q): bool {
    return $acc && s4_allowed($q);
}, true), implode(' | ', array_unique(array_map('s4_shape', $scanWrites))));

delete_option('seolab_scan_last');
$captured = [];
$uuid = (string) ($scan['report'] ?? '');
$verify = $actions->verify('POST', [
    'report' => $uuid,
    'post_id' => '999999',
    'seo_lab_nonce' => wp_create_nonce('seo_lab_verify_' . get_current_blog_id() . '_' . $uuid),
]);
$verifyWrites = $captured;
s4_win('verify_ok', ($verify['ok'] ?? false) === true, (string) ($verify['error'] ?? ''));
s4_win('verify_scritture_solo_seolab', array_reduce($verifyWrites, static function (bool $acc, string $q): bool {
    return $acc && s4_allowed($q);
}, true), implode(' | ', array_unique(array_map('s4_shape', $verifyWrites))));

// Cooldown: uno scan subito dopo il Verify deve essere respinto prima della cattura.
$stored = get_option('seolab_scan_last', null);
$again = $actions->scan('POST', ['post_id' => (string) $postId, 'seo_lab_nonce' => wp_create_nonce('seo_lab_scan_' . get_current_blog_id())]);
s4_win('scan_subito_dopo_verify_rate_limited', ($again['error'] ?? '') === 'rate_limited',
    'valore=' . var_export($stored, true) . ' esito=' . (string) ($again['error'] ?? ($again['ok'] ?? '')));

