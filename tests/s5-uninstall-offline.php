<?php
/*
 * Prova offline di uninstall.php (U-1): wpdb, funzioni multisite e wp_die sono doppi, nessun database.
 * Copre la lettura dei siti che fallisce, il primo batch vuoto e il batch successivo in errore.
 * Uso: php tests/s5-uninstall-offline.php
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    define('WP_UNINSTALL_PLUGIN', 'seo-lab/seo-lab.php');
}

$uninstall = dirname(__DIR__) . '/uninstall.php';
$passed = 0;
$failed = 0;

function check($ok, $name)
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "OK $name\n";
    } else {
        $failed++;
        echo "FAIL $name\n";
    }
}

class SeolabTestWpdb
{
    public $prefix = 'wp_';
    public $blogs = 'wp_blogs';
    public $last_error = '';
    public $queries = [];

    public function prepare($sql, ...$args)
    {
        return $sql;
    }

    public function get_col($sql)
    {
        $index = $GLOBALS['S']['calls']++;
        $batch = isset($GLOBALS['S']['batches'][$index]) ? $GLOBALS['S']['batches'][$index] : ['ids' => [], 'error' => ''];
        $this->last_error = $batch['error'];
        return $batch['ids'];
    }

    public function suppress_errors($value)
    {
        return false;
    }

    public function query($sql)
    {
        $this->queries[] = $sql;
        return true;
    }
}

function is_multisite()
{
    return $GLOBALS['S']['multisite'];
}
function switch_to_blog($id)
{
    $GLOBALS['S']['stack'][] = $GLOBALS['wpdb']->prefix;
    $GLOBALS['wpdb']->prefix = 'wp_' . $id . '_';
}
function restore_current_blog()
{
    $GLOBALS['wpdb']->prefix = array_pop($GLOBALS['S']['stack']);
}
function delete_option($name)
{
    $GLOBALS['S']['deleted'][] = $name;
    return true;
}
function esc_html__($text, $domain)
{
    return $text;
}
function wp_die($message)
{
    $GLOBALS['S']['died'][] = $message;
}

function run_uninstall($uninstall, array $scenario)
{
    $GLOBALS['S'] = [
        'multisite' => $scenario['multisite'],
        'batches' => $scenario['batches'],
        'calls' => 0,
        'stack' => [],
        'deleted' => [],
        'died' => [],
    ];
    $GLOBALS['wpdb'] = new SeolabTestWpdb();
    include $uninstall;
    return [$GLOBALS['S'], $GLOBALS['wpdb']];
}

// A. Multisite con due siti, nessun errore: DROP su entrambi, prefisso ripristinato, nessun fallimento.
list($state, $db) = run_uninstall($uninstall, [
    'multisite' => true,
    'batches' => [['ids' => [1, 2], 'error' => '']],
]);
check($state['died'] === [], 'multisite ok: nessun wp_die');
check(in_array('DROP TABLE IF EXISTS `wp_1_seolab_reports`', $db->queries, true) && in_array('DROP TABLE IF EXISTS `wp_2_seolab_reports`', $db->queries, true), 'multisite ok: DROP su entrambi i siti');
check($db->prefix === 'wp_', 'multisite ok: contesto del blog ripristinato');

// B. Lettura dei siti in errore al primo batch: nessun DROP, nessuna opzione rimossa, fallimento segnalato.
list($state, $db) = run_uninstall($uninstall, [
    'multisite' => true,
    'batches' => [['ids' => [], 'error' => 'SELECT command denied to user']],
]);
check(count($state['died']) === 1, 'lettura siti in errore: wp_die chiamato');
check($db->queries === [] && $state['deleted'] === [], 'lettura siti in errore: nessun DROP e nessuna opzione rimossa');

// C. Primo batch vuoto senza errore: in multisite il sito 1 esiste sempre, quindi e' un fallimento.
list($state, $db) = run_uninstall($uninstall, [
    'multisite' => true,
    'batches' => [['ids' => [], 'error' => '']],
]);
check(count($state['died']) === 1 && $db->queries === [], 'primo batch vuoto: fallimento, nessun DROP');

// D. Risposta non valida dal database (null): fallimento.
list($state, $db) = run_uninstall($uninstall, [
    'multisite' => true,
    'batches' => [['ids' => null, 'error' => '']],
]);
check(count($state['died']) === 1 && $db->queries === [], 'risposta non array: fallimento, nessun DROP');

// E. Primo batch pieno (100 siti) e batch successivo in errore: i 100 siti del primo batch sono gestiti, il fallimento e' segnalato.
$first = range(1, 100);
list($state, $db) = run_uninstall($uninstall, [
    'multisite' => true,
    'batches' => [
        ['ids' => $first, 'error' => ''],
        ['ids' => [], 'error' => 'Lost connection to MySQL server'],
    ],
]);
check(count($state['died']) === 1, 'batch successivo in errore: fallimento segnalato');
check(count($db->queries) === 100 && $state['calls'] === 2, 'batch successivo in errore: 100 DROP del primo batch, nessun batch oltre il secondo');
check($db->prefix === 'wp_', 'batch successivo in errore: contesto ripristinato');

// F. Sito singolo (non multisite): DROP sul prefisso corrente e opzioni rimosse.
list($state, $db) = run_uninstall($uninstall, [
    'multisite' => false,
    'batches' => [],
]);
check($state['died'] === [] && $db->queries === ['DROP TABLE IF EXISTS `wp_seolab_reports`'], 'sito singolo: DROP del solo prefisso corrente');
check($state['deleted'] === ['seolab_schema_version', 'seolab_scan_last'], 'sito singolo: opzioni SEO Lab rimosse');

echo "PASS $passed FAIL $failed\n";
exit($failed === 0 ? 0 : 1);
