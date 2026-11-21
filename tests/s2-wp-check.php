<?php

/*
 * Sonda S2 su WordPress reale, eseguita dal container wordpress:cli con "wp eval-file" (tests/s2-wordpress.sh).
 * Legge le variabili S2_PHASE (no-yoast | yoast | yoast-admin | schema-off-admin), S2_POST_ID e S2_PROBE_URL, non
 * modifica contenuti o impostazioni
 * e stampa una riga JSON preceduta da "S2WP ". Il confronto con il frontend e' una sonda separata: la capture S1
 * rifiuta indirizzi privati, quindi la diagnosi da capture resta bloccata dal perimetro di rete della sandbox.
 */

spl_autoload_register(static function (string $class): void {
    $roots = [
        'SeoLab\\Contracts\\' => '/seolab/src/Contracts/',
        'SeoLab\\Capture\\' => '/seolab/src/Capture/',
        'SeoLab\\Expected\\' => '/seolab/src/Expected/',
        'SeoLab\\Rules\\' => '/seolab/src/Rules/',
        'SeoLab\\Diagnosis\\' => '/seolab/src/Diagnosis/',
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

use SeoLab\Capture\HtmlExtractor;
use SeoLab\Capture\SafeHttpCapturer;
use SeoLab\Capture\SystemAddressResolver;
use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Diagnosis\CanonicalRobotsDiagnoser;
use SeoLab\Expected\YoastExpectedProvider;

/**
 * Provider a valore fisso per la diagnosi di questa sonda (una sola lettura, gia' effettuata sopra).
 * Dichiarato prima dell'uso: "wp eval-file" non risolve le classi dichiarate in fondo allo script.
 */
final class FixedProvider implements \SeoLab\Contracts\ExpectedProvider
{
    /** @var ExpectedSnapshot */
    private $snapshot;

    public function __construct(ExpectedSnapshot $snapshot)
    {
        $this->snapshot = $snapshot;
    }

    public function forPost(int $postId): ExpectedSnapshot
    {
        return $this->snapshot;
    }
}

$phase = (string) getenv('S2_PHASE');
$postId = (int) getenv('S2_POST_ID');
$probeUrl = (string) getenv('S2_PROBE_URL');

// Le fasi "-admin" bootstrappano con --context=admin; le altre restano non admin (nessuna schermata admin simulata).
$adminPhase = substr($phase, -6) === '-admin';
if ($adminPhase) {
    require_once ABSPATH . 'wp-admin/includes/admin.php';
}
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user(is_array($admins) && $admins !== [] ? (int) $admins[0] : 1);
if ($adminPhase) {
    set_current_screen('dashboard');
}

$report = [
    'phase' => $phase,
    'wp_version' => get_bloginfo('version'),
    'php_version' => PHP_VERSION,
    'yoast_function' => function_exists('YoastSEO'),
    'yoast_version' => defined('WPSEO_VERSION') ? WPSEO_VERSION : null,
    'admin_context' => is_admin(),
    'user_can_manage_options' => current_user_can('manage_options'),
    'post_id' => $postId,
];

$provider = new YoastExpectedProvider();
$expected = $provider->forPost($postId);
$report['expected_reason'] = $provider->lastReason();
$report['jsonld_reason'] = $provider->lastJsonLdReason();
$report['jsonld_guard'] = $provider->lastGuardEvidence();
$report['permalink'] = get_permalink($postId);
$report['watched_hooks'] = watched_hooks_summary();
$report['expected_admin'] = snapshot_summary($expected);
$report['expected_missing_id'] = snapshot_summary($provider->forPost(999999));

$capturer = new SafeHttpCapturer(new SystemAddressResolver(), static function (): float {
    return microtime(true);
}, sys_get_temp_dir(), true);
$observed = $capturer->captureForPost($postId);
$report['capture'] = [
    'status' => $observed->status(),
    'reason' => $observed->captureReason(),
];
$outcomes = (new CanonicalRobotsDiagnoser(new FixedProvider($expected)))->diagnose($postId, $observed);
$report['outcomes'] = [];
foreach ($outcomes as $outcome) {
    $report['outcomes'][$outcome->ruleId()] = $outcome->state();
}

$report['frontend'] = null;
if ($probeUrl !== '') {
    $report['frontend'] = frontend_probe($probeUrl);
}

echo 'S2WP ' . json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";

/**
 * @return array<string, mixed>
 */
function snapshot_summary(ExpectedSnapshot $snapshot): array
{
    $out = [];
    foreach (['canonical', 'robots', 'jsonld'] as $family) {
        $value = $snapshot->resolved($family);
        $out[$family] = ['availability' => $value->availability(), 'value' => $value->value(), 'source' => $value->source()];
    }
    return $out;
}

/**
 * Canonical e robots emessi dal frontend (head e body), letti con DOMDocument. Nessuna inferenza: solo valori.
 *
 * @return array<string, mixed>
 */
function frontend_probe(string $url): array
{
    $context = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "Accept: text/html\r\n"]]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        return ['fetched' => false, 'url' => $url];
    }
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML($body, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $canonical = [];
    $robots = [];
    foreach ($doc->getElementsByTagName('link') as $link) {
        if (strtolower(trim($link->getAttribute('rel'))) === 'canonical') {
            $canonical[] = $link->getAttribute('href');
        }
    }
    foreach ($doc->getElementsByTagName('meta') as $meta) {
        if (strtolower($meta->getAttribute('name')) === 'robots') {
            $robots[] = $meta->getAttribute('content');
        }
    }
    return ['fetched' => true, 'url' => $url, 'canonical' => $canonical, 'robots' => $robots, 'jsonld' => jsonld_probe($body, $url)];
}

/**
 * JSON-LD del frontend letto con l'estrattore S3 (sola lettura): analisi completa e ID delle definizioni nel profilo.
 * Nessuna inferenza: solo flag, codici e ID.
 *
 * @return array<string, mixed>
 */
function jsonld_probe(string $body, string $url): array
{
    $extracted = HtmlExtractor::extract($body, null, $url, true, []);
    if (isset($extracted['error'])) {
        return ['error' => (string) $extracted['error']];
    }
    $definitions = [];
    foreach ($extracted['values']['jsonld']['value'] ?? [] as $node) {
        if (($node['profile'] ?? false) === true && $node['id'] !== null) {
            $definitions[] = $node['id'];
        }
    }
    return ['analysis' => $extracted['jsonld_analysis'], 'definitions' => $definitions, 'availability' => $extracted['values']['jsonld']['availability']];
}

/**
 * Hook controllati da S4 con i callback registrati (classe::metodo o funzione), in sola lettura.
 *
 * @return array<string, string[]>
 */
function watched_hooks_summary(): array
{
    $watched = ['wpseo_json_ld_output', 'wpseo_json_ld', 'wpseo_frontend_presenters', 'wpseo_frontend_presenter_classes',
        'wpseo_frontend_presentation', 'wpseo_dynamic_permalinks_enabled'];
    $out = [];
    foreach ((array) ($GLOBALS['wp_filter'] ?? []) as $hook => $container) {
        $hook = (string) $hook;
        $isWatched = in_array($hook, $watched, true) || strpos($hook, 'wpseo_schema_') === 0 || strpos($hook, 'wpseo_pre_schema_block_type_') === 0;
        if (!$isWatched || !is_object($container) || !isset($container->callbacks) || !is_array($container->callbacks)) {
            continue;
        }
        foreach ($container->callbacks as $callbacks) {
            foreach ((array) $callbacks as $callback) {
                $function = $callback['function'] ?? null;
                if (is_string($function)) {
                    $id = $function;
                } elseif (is_array($function) && count($function) === 2) {
                    $id = (is_object($function[0]) ? get_class($function[0]) : (string) $function[0]) . '::' . (string) $function[1];
                } else {
                    $id = 'closure';
                }
                $out[$hook][] = $id;
            }
        }
    }
    return $out;
}
