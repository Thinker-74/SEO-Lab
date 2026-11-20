<?php

declare(strict_types=1);

/*
 * Verificatore S1 di SEO Lab: cattura HTTP difensiva da ID WordPress (SeoLab\Capture\SafeHttpCapturer).
 * Uso: php tests/s1-capture.php
 * Uscita 0 solo se tutti i requisiti passano. Trasporto (wp_safe_remote_get), funzioni WordPress, DNS e
 * orologio monotono sono stub controllati: nessuna rete reale. Ogni warning, notice o deprecation e' un errore.
 */

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

spl_autoload_register(static function (string $class): void {
    $roots = [
        'SeoLab\\Contracts\\' => 'src/Contracts/',
        'SeoLab\\Capture\\' => 'src/Capture/',
    ];
    foreach ($roots as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            $file = dirname(__DIR__) . '/' . $dir . substr($class, strlen($prefix)) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use SeoLab\Capture\AddressResolver;
use SeoLab\Capture\IpClassifier;
use SeoLab\Capture\SafeHttpCapturer;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\RuleCatalog;
use SeoLab\Contracts\ScanReport;
use SeoLab\Contracts\TrustedContent;

// ---------------------------------------------------------------------------
// Stub WordPress (namespace globale). Leggono lo stato di test da $GLOBALS.
// ---------------------------------------------------------------------------

class WP_Error
{
    /** @var string */
    private $code;

    /** @var string */
    private $message;

    public function __construct(string $code, string $message)
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

function get_post($id)
{
    return $GLOBALS['wp']['posts'][$id] ?? null;
}

function get_permalink($id)
{
    return $GLOBALS['wp']['permalinks'][$id] ?? false;
}

function home_url($path = '')
{
    return $GLOBALS['wp']['home'];
}

function wp_safe_remote_get($url, $args = [])
{
    $GLOBALS['net']['calls'][] = ['url' => $url, 'args' => $args];

    // Pulizia per hop: al momento di ogni chiamata deve esistere esattamente il file di questo hop.
    $present = glob(dirname((string) ($args['filename'] ?? '')) . '/seolab-*') ?: [];
    if (count($present) !== 1 || $present[0] !== ($args['filename'] ?? null)) {
        $GLOBALS['net']['violations'][] = 'file temporanei non unici all ingresso della chiamata: ' . count($present);
    }

    $problems = [];
    if (($args['redirection'] ?? null) !== 0) {
        $problems[] = 'redirection';
    }
    if (($args['decompress'] ?? null) !== false) {
        $problems[] = 'decompress';
    }
    if (($args['stream'] ?? null) !== true || !isset($args['filename']) || !is_string($args['filename'])) {
        $problems[] = 'stream/filename';
    }
    if (!isset($args['limit_response_size']) || !is_int($args['limit_response_size'])) {
        $problems[] = 'limit_response_size';
    }
    if (($args['cookies'] ?? null) !== []) {
        $problems[] = 'cookies';
    }
    if (strtolower((string) ($args['headers']['Accept-Encoding'] ?? '')) !== 'identity') {
        $problems[] = 'accept-encoding';
    }
    if (isset($args['headers']['Cookie']) || isset($args['headers']['Authorization'])) {
        $problems[] = 'credenziali';
    }
    if (!isset($args['timeout']) || !is_numeric($args['timeout']) || $args['timeout'] <= 0 || $args['timeout'] > 5) {
        $problems[] = 'timeout';
    }
    if ($problems !== []) {
        $GLOBALS['net']['violations'][] = $url . ': ' . implode(',', $problems);
    }

    $queue = $GLOBALS['net']['script'][$url] ?? [];
    if ($queue === []) {
        $GLOBALS['net']['violations'][] = 'richiesta non prevista: ' . $url;
        return new WP_Error('unscripted', 'richiesta non prevista');
    }
    $step = array_shift($GLOBALS['net']['script'][$url]);
    $GLOBALS['clock'] += (float) ($step['advance'] ?? 0.0);

    if (isset($step['throw'])) {
        throw new \RuntimeException($step['throw']);
    }
    if (array_key_exists('raw', $step)) {
        return $step['raw'];
    }
    if (isset($step['error'])) {
        return new WP_Error($step['error'][0], $step['error'][1]);
    }
    $body = (string) ($step['body'] ?? '');
    $limit = (int) $args['limit_response_size'];
    file_put_contents($args['filename'], substr($body, 0, $limit));

    return [
        'response' => ['code' => $step['code']],
        'headers' => $step['headers'] ?? [],
        'body' => '',
    ];
}

// ---------------------------------------------------------------------------
// Doppi di test e helper
// ---------------------------------------------------------------------------

final class FakeResolver implements AddressResolver
{
    /** @var array<string, array> valori per host: lista di IP, oppure lista di liste (una per chiamata) */
    private $table;

    public function __construct(array $table)
    {
        $this->table = $table;
    }

    public function resolve(string $host): array
    {
        $GLOBALS['dns_calls'][] = $host;
        if (!isset($this->table[$host])) {
            return [];
        }
        $entry = $this->table[$host];
        if ($entry !== [] && is_array($entry[0])) {
            if (count($entry) > 1) {
                return array_shift($this->table[$host]);
            }
            return $entry[0];
        }
        return $entry;
    }
}

const HOME = 'https://example.test/';
const PERM1 = 'https://example.test/pagina-uno/';
const CAP = 1048576;
const SENTINEL_ERR = 'SENTINEL-ERR-91bc';
const SENTINEL_COOKIE = 'SENTINEL-COOKIE-7f3a';
const SCAN_UUID = '00000000-0000-4000-8000-0000000001a1';

$TEMP_DIR = sys_get_temp_dir() . '/seolab-s1-test-' . getmypid();
if (!is_dir($TEMP_DIR) && !mkdir($TEMP_DIR, 0700, true)) {
    fwrite(STDERR, "impossibile creare la cartella temporanea di test\n");
    exit(2);
}

$passed = 0;
$failures = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failures;
    if ($ok) {
        $passed++;
        return;
    }
    $failures[] = $label . ($detail !== '' ? ' [' . $detail . ']' : '');
}

function step(int $code, string $body = '', array $headers = [], float $advance = 0.0): array
{
    return ['code' => $code, 'body' => $body, 'headers' => $headers, 'advance' => $advance];
}

function post_obj(string $status = 'publish', string $type = 'post', string $password = ''): object
{
    return (object) ['post_status' => $status, 'post_type' => $type, 'post_password' => $password];
}

function page(string $head, string $body = '<main><h1>Pagina</h1></main>', bool $charset = true): string
{
    return '<!doctype html><html lang="it"><head>' . ($charset ? '<meta charset="utf-8">' : '')
        . '<title>Pagina</title>' . $head . '</head><body>' . $body . '</body></html>';
}

/**
 * Esegue la cattura da ID (o da TrustedContent) con lo stato di test e restituisce esito, chiamate e violazioni.
 */
function run_scan(array $cfg = [], ?TrustedContent $trusted = null): array
{
    global $TEMP_DIR;
    $GLOBALS['wp'] = [
        'home' => $cfg['home'] ?? HOME,
        'posts' => $cfg['posts'] ?? [1 => post_obj()],
        'permalinks' => $cfg['permalinks'] ?? [1 => PERM1],
    ];
    $GLOBALS['net'] = [
        'script' => $cfg['script'] ?? [],
        'calls' => [],
        'violations' => [],
    ];
    $GLOBALS['clock'] = 1000.0;
    $GLOBALS['dns_calls'] = [];
    $dns = $cfg['dns'] ?? ['example.test' => ['93.184.216.34']];
    $dir = $cfg['temp_dir'] ?? $TEMP_DIR;

    $resolver = $cfg['resolver'] ?? new FakeResolver($dns);
    $monotonic = static function (): float {
        return (float) $GLOBALS['clock'];
    };
    $capturer = isset($cfg['default_limit'])
        ? new SafeHttpCapturer($resolver, $monotonic, $dir, $cfg['dom'] ?? true)
        : new SafeHttpCapturer($resolver, $monotonic, $dir, $cfg['dom'] ?? true, $cfg['limit'] ?? CAP);

    $snap = null;
    $error = null;
    try {
        $snap = $trusted !== null ? $capturer->capture($trusted) : $capturer->captureForPost($cfg['post'] ?? 1);
    } catch (\Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }

    $leftovers = glob($dir . '/seolab-*') ?: [];
    check('temp pulito dopo la cattura', $leftovers === [], implode(',', $leftovers));

    $json = null;
    if ($snap instanceof ObservedSnapshot) {
        $json = json_encode(report_for($snap)->toArray());
    }

    return [
        'snap' => $snap,
        'error' => $error,
        'calls' => $GLOBALS['net']['calls'],
        'violations' => $GLOBALS['net']['violations'],
        'json' => $json,
    ];
}

function report_for(ObservedSnapshot $snap): ScanReport
{
    $expected = [];
    foreach (['configured', 'resolved'] as $layer) {
        $expected[$layer] = [];
        foreach (RuleCatalog::families() as $family) {
            $expected[$layer][$family] = ['availability' => 'unavailable', 'value' => null, 'source' => null];
        }
    }
    $data = [
        'scan_id' => SCAN_UUID,
        'schema_version' => '1',
        'post_id' => 1,
        'source' => ['provider' => 'yoast', 'provider_version' => 'test'],
        'expected' => $expected,
        'findings' => [],
        'rule_outcomes' => [],
    ] + $snap->toArray();
    return new ScanReport($data);
}

function expect_state(string $label, array $run, string $status, ?string $reason, int $calls): void
{
    if ($run['error'] !== null) {
        check($label, false, 'eccezione: ' . $run['error']);
        return;
    }
    $snap = $run['snap'];
    check($label . ' stato', $snap->status() === $status, 'atteso ' . $status . ', ottenuto ' . $snap->status());
    check($label . ' motivo', $snap->captureReason() === $reason, 'atteso ' . var_export($reason, true) . ', ottenuto ' . var_export($snap->captureReason(), true));
    check($label . ' chiamate HTTP', count($run['calls']) === $calls, 'attese ' . $calls . ', ottenute ' . count($run['calls']));
    check($label . ' contratto violazioni', $run['violations'] === [], implode(';', $run['violations']));
}

function ok_page(string $body, array $headers = [], array $more = []): array
{
    $has = false;
    foreach (array_keys($headers) as $key) {
        if (strtolower((string) $key) === 'content-type') {
            $has = true;
        }
    }
    if (!$has) {
        $headers['Content-Type'] = 'text/html; charset=UTF-8';
    }
    return run_scan(['script' => [PERM1 => [step(200, $body, $headers)]]] + $more);
}

function values_of(ObservedSnapshot $snap, string $family): array
{
    $value = $snap->observedValue($family);
    return ['availability' => $value->availability(), 'value' => $value->value()];
}

function assert_no_sentinel(string $label, array $run, string $sentinel): void
{
    if ($run['json'] === null) {
        check($label . ' report serializzato', false, 'nessuno snapshot');
        return;
    }
    check($label . ' assenza di ' . $sentinel, strpos($run['json'], $sentinel) === false);
}

// ---------------------------------------------------------------------------
// 1. Selezione WordPress: nessuna richiesta HTTP prima del rifiuto
// ---------------------------------------------------------------------------

$r = run_scan(['script' => [PERM1 => [step(200, page(''), ['Content-Type' => 'text/html'])]]]);
expect_state('selezione post pubblico', $r, 'ok', null, 1);

$r = run_scan(['posts' => [2 => post_obj('publish', 'page')], 'permalinks' => [2 => 'https://example.test/pagina/'], 'post' => 2,
    'script' => ['https://example.test/pagina/' => [step(200, page(''), ['Content-Type' => 'text/html'])]]]);
expect_state('selezione page pubblica', $r, 'ok', null, 1);

$r = run_scan(['posts' => []]);
expect_state('ID assente', $r, 'blocked', 'content_ineligible', 0);

foreach (['draft', 'pending', 'private', 'future', 'trash'] as $status) {
    $r = run_scan(['posts' => [1 => post_obj($status)]]);
    expect_state('stato ' . $status, $r, 'blocked', 'content_ineligible', 0);
}

$r = run_scan(['posts' => [1 => post_obj('publish', 'post', 'segreto')]]);
expect_state('pagina protetta da password', $r, 'blocked', 'content_ineligible', 0);

$r = run_scan(['posts' => [1 => post_obj('publish', 'attachment')]]);
expect_state('tipo di post non ammesso', $r, 'blocked', 'content_ineligible', 0);

$r = run_scan(['permalinks' => [1 => 'https://evil.example/pagina-uno/']]);
expect_state('permalink fuori origine', $r, 'blocked', 'content_ineligible', 0);

$r = run_scan(['permalinks' => [1 => 'http://example.test/pagina-uno/']]);
expect_state('permalink con schema diverso dall origine', $r, 'blocked', 'content_ineligible', 0);

$r = run_scan(['permalinks' => [1 => 'https://example.test:8443/pagina-uno/']]);
expect_state('permalink con porta diversa dall origine', $r, 'blocked', 'content_ineligible', 0);

$r = run_scan(['permalinks' => [1 => false]]);
expect_state('permalink assente dal core', $r, 'blocked', 'content_ineligible', 0);

$r = run_scan(
    ['script' => [PERM1 => [step(200, page(''))]]],
    new TrustedContent(1, 'https://example.test/altro/')
);
expect_state('TrustedContent discordante', $r, 'blocked', 'content_ineligible', 0);

$r = run_scan(
    ['script' => [PERM1 => [step(200, page(''), ['Content-Type' => 'text/html'])]]],
    new TrustedContent(1, PERM1)
);
expect_state('TrustedContent concordante', $r, 'ok', null, 1);

// ---------------------------------------------------------------------------
// 2. Origine e DNS
// ---------------------------------------------------------------------------

$r = run_scan(['dns' => ['example.test' => ['10.0.0.5', '93.184.216.34']]]);
expect_state('DNS misto con privato', $r, 'blocked', 'origin_not_public', 0);

$r = run_scan(['dns' => ['example.test' => ['127.0.0.1']]]);
expect_state('DNS loopback', $r, 'blocked', 'origin_not_public', 0);

$r = run_scan(['dns' => ['example.test' => []]]);
expect_state('DNS senza indirizzi', $r, 'failed', 'origin_resolution_failed', 0);

$r = run_scan(['dns' => ['example.test' => ['2606:4700:4700::1111']], 'script' => [PERM1 => [step(200, page(''), ['Content-Type' => 'text/html'])]]]);
expect_state('DNS IPv6 pubblico', $r, 'ok', null, 1);

$r = run_scan(['dns' => ['example.test' => ['fd12::1']]]);
expect_state('DNS IPv6 ULA', $r, 'blocked', 'origin_not_public', 0);

$r = run_scan(['dns' => ['example.test' => ['::ffff:127.0.0.1']]]);
expect_state('DNS IPv4 mappato su loopback', $r, 'blocked', 'origin_not_public', 0);

$r = run_scan(['home' => 'https://93.184.216.34/']);
expect_state('origine con IP letterale', $r, 'blocked', 'origin_not_public', 0);

$r = run_scan(['home' => 'https://localhost/', 'permalinks' => [1 => 'https://localhost/pagina-uno/']]);
expect_state('origine senza dominio pubblico valido', $r, 'blocked', 'origin_not_public', 0);

// Classificatore IP: stessa tabella su PHP 7.4 e 8.3, senza dipendere dai flag di filter_var.
$ipTable = [
    '8.8.8.8' => true, '93.184.216.34' => true, '172.32.0.1' => true, '100.128.0.1' => true,
    '198.20.0.1' => true, '10.0.0.1' => false, '172.16.0.1' => false, '192.168.1.1' => false,
    '100.64.0.1' => false, '169.254.1.1' => false, '0.0.0.0' => false, '0.1.2.3' => false,
    '127.0.0.1' => false, '198.18.0.1' => false, '224.0.0.1' => false, '240.0.0.1' => false,
    '255.255.255.255' => false, '192.0.2.1' => false, '198.51.100.7' => false, '203.0.113.9' => false,
    '::1' => false, '::' => false, 'fe80::1' => false, 'fc00::1' => false, 'fd12::1' => false,
    '::ffff:127.0.0.1' => false, '::ffff:8.8.8.8' => true, '2606:4700:4700::1111' => true,
    '2001:db8::1' => false, '2002::1' => false, '2001::1' => false, 'ff02::1' => false,
    '100::1' => false, 'fec0::1' => false, 'not-an-ip' => false,
];
foreach ($ipTable as $ip => $expected) {
    check('classificatore IP ' . $ip, IpClassifier::isPublic($ip) === $expected, 'atteso ' . var_export($expected, true));
}

// ---------------------------------------------------------------------------
// 3. Trasporto: contratto della chiamata, limiti, cleanup
// ---------------------------------------------------------------------------

$r = run_scan(['script' => [PERM1 => [step(200, page(''), ['Content-Type' => 'text/html'])]]]);
check('timeout iniziale 5 s', count($r['calls']) === 1 && abs((float) $r['calls'][0]['args']['timeout'] - 5.0) < 1e-9);

$r = ok_page(page(''), ['Content-Encoding' => 'gzip']);
expect_state('Content-Encoding gzip rifiutato', $r, 'failed', 'content_encoding_unsupported', 1);

$r = ok_page(page(''), ['content-encoding' => 'identity']);
expect_state('Content-Encoding identity accettato', $r, 'ok', null, 1);

$r = ok_page(str_repeat('a', 300) . page(''), ['Content-Length' => '2000']);
expect_state('Content-Length annunciato superiore ai byte', $r, 'partial', 'body_truncated', 1);

$big = page('<link rel="canonical" href="https://example.test/pagina-uno/">') . str_repeat(' ', 1200);
$r = run_scan(['limit' => 1000, 'script' => [PERM1 => [step(200, $big, ['Content-Type' => 'text/html'])]]]);
expect_state('corpo oltre soglia', $r, 'partial', 'body_limit', 1);
if ($r['snap'] instanceof ObservedSnapshot) {
    check('hash parziale sui byte del limite', $r['snap']->bodySha256() === hash('sha256', substr($big, 0, 1000)));
    check('valori parziali unobservable', values_of($r['snap'], 'canonical')['availability'] === 'unobservable');
}

$r = run_scan(['script' => [PERM1 => [step(206, page(''), ['Content-Type' => 'text/html'])]]]);
expect_state('HTTP 206', $r, 'partial', 'partial_content', 1);

$r = run_scan(['script' => [PERM1 => [['error' => ['http_request_failed', 'cURL error 28: Operation timed out after 5000 milliseconds, ' . SENTINEL_ERR]]]]]);
expect_state('timeout di trasporto', $r, 'failed', 'timeout', 1);
assert_no_sentinel('timeout', $r, SENTINEL_ERR);

$r = run_scan(['script' => [PERM1 => [['error' => ['http_request_failed', 'connection reset ' . SENTINEL_ERR]]]]]);
expect_state('errore di trasporto avviato', $r, 'failed', 'transport_error', 1);
assert_no_sentinel('errore di trasporto', $r, SENTINEL_ERR);

$r = run_scan(['temp_dir' => $TEMP_DIR . '/inesistente', 'script' => [PERM1 => [step(200, page(''))]]]);
expect_state('area temporanea non disponibile', $r, 'failed', 'temporary_storage_unavailable', 0);

$r = ok_page(page(''), ['Set-Cookie' => 'sid=' . SENTINEL_COOKIE, 'Content-Type' => 'text/html']);
expect_state('cookie di risposta non persistito', $r, 'ok', null, 1);
assert_no_sentinel('Set-Cookie', $r, SENTINEL_COOKIE);

// Orologio: budget di 5 s per l'intera catena, timeout residuo per hop.
$r = run_scan(['script' => [
    PERM1 => [step(301, '', ['Location' => '/a/'], 4.0)],
    'https://example.test/a/' => [step(200, page(''), ['Content-Type' => 'text/html'])],
]]);
expect_state('budget residuo dopo 4 s', $r, 'ok', null, 2);
check('timeout residuo del secondo hop 1 s', count($r['calls']) === 2 && abs((float) $r['calls'][1]['args']['timeout'] - 1.0) < 1e-9,
    isset($r['calls'][1]) ? (string) $r['calls'][1]['args']['timeout'] : 'nessuna chiamata');

$r = run_scan(['script' => [PERM1 => [step(301, '', ['Location' => '/a/'], 5.5)]]]);
expect_state('budget esaurito prima del secondo hop', $r, 'failed', 'timeout', 1);

// ---------------------------------------------------------------------------
// 4. Redirect: solo salti validati, massimo tre
// ---------------------------------------------------------------------------

$r = run_scan(['script' => [
    PERM1 => [step(301, '', ['Location' => '/nuovo/?x=1'])],
    'https://example.test/nuovo/?x=1' => [step(200, page(''), ['Content-Type' => 'text/html'])],
]]);
expect_state('redirect relativo con query', $r, 'ok', null, 2);
check('redirect registrato', $r['snap'] instanceof ObservedSnapshot && $r['snap']->redirects() === ['https://example.test/nuovo/?x=1']);
check('URL finale osservato', $r['snap'] instanceof ObservedSnapshot && $r['snap']->observedUrl() === 'https://example.test/nuovo/?x=1');

$r = run_scan(['script' => [
    PERM1 => [step(302, '', ['location' => '/a/'])],
    'https://example.test/a/' => [step(301, '', ['Location' => '/b/'])],
    'https://example.test/b/' => [step(308, '', ['Location' => '/c/'])],
    'https://example.test/c/' => [step(200, page(''), ['Content-Type' => 'text/html'])],
]]);
expect_state('tre redirect ammessi', $r, 'ok', null, 4);

$r = run_scan(['script' => [
    PERM1 => [step(301, '', ['Location' => '/a/'])],
    'https://example.test/a/' => [step(301, '', ['Location' => '/b/'])],
    'https://example.test/b/' => [step(301, '', ['Location' => '/c/'])],
    'https://example.test/c/' => [step(301, '', ['Location' => '/d/'])],
]]);
expect_state('quarto redirect rifiutato', $r, 'blocked', 'redirect_limit', 4);
check('quarto hop non richiesto', !in_array('https://example.test/d/', array_column($r['calls'], 'url'), true));

$r = run_scan(['script' => [
    PERM1 => [step(301, '', ['Location' => '/a/'])],
    'https://example.test/a/' => [step(301, '', ['Location' => PERM1])],
]]);
expect_state('loop di redirect', $r, 'blocked', 'redirect_rejected', 2);

$r = run_scan(['script' => [PERM1 => [step(301, '', ['Location' => 'https://evil.example/'])]]]);
expect_state('redirect verso altra origine', $r, 'blocked', 'redirect_rejected', 1);
check('nessuna chiamata verso altra origine', !in_array('https://evil.example/', array_column($r['calls'], 'url'), true));

$r = run_scan(['script' => [PERM1 => [step(301, '', ['Location' => 'http://example.test/a/'])]]]);
expect_state('redirect con downgrade di schema', $r, 'blocked', 'redirect_rejected', 1);

$r = run_scan(['script' => [PERM1 => [step(301, '', ['Location' => 'https://user:pw@example.test/a/'])]]]);
expect_state('redirect con userinfo', $r, 'blocked', 'redirect_rejected', 1);

$r = run_scan(['script' => [PERM1 => [step(301, '', ['Location' => ''])]]]);
expect_state('Location vuota', $r, 'blocked', 'redirect_rejected', 1);

$r = run_scan(['script' => [PERM1 => [step(301, '', [])]]]);
expect_state('Location assente', $r, 'blocked', 'redirect_rejected', 1);

$r = run_scan(['script' => [PERM1 => [step(301, '', ['Location' => ['/a/', '/b/']])]]]);
expect_state('Location multipla', $r, 'blocked', 'redirect_rejected', 1);

$r = run_scan([
    'dns' => ['example.test' => [['93.184.216.34'], ['10.0.0.9']]],
    'script' => [PERM1 => [step(301, '', ['Location' => '/a/'])], 'https://example.test/a/' => [step(200, page(''), ['Content-Type' => 'text/html'])]],
]);
expect_state('rebinding verso privato al secondo hop', $r, 'blocked', 'origin_not_public', 1);

$r = run_scan(['script' => [PERM1 => [step(301, '', ['Location' => '../x/?q=2'])],
    'https://example.test/x/?q=2' => [step(200, page(''), ['Content-Type' => 'text/html'])]]]);
expect_state('Location con segmenti punto', $r, 'ok', null, 2);

// ---------------------------------------------------------------------------
// 5. Stati HTTP e tipo di risposta
// ---------------------------------------------------------------------------

foreach ([401, 403, 429] as $code) {
    $r = run_scan(['script' => [PERM1 => [step($code, 'bloccato')]]]);
    expect_state('HTTP ' . $code, $r, 'blocked', 'http_blocked', 1);
}

foreach ([404, 304, 500] as $code) {
    $r = run_scan(['script' => [PERM1 => [step($code, '')]]]);
    expect_state('HTTP ' . $code . ' non pertinente', $r, 'failed', 'http_status_unexpected', 1);
}

$r = ok_page(page(''), ['Content-Type' => 'application/pdf']);
expect_state('tipo non HTML', $r, 'failed', 'unsupported_response', 1);

$r = run_scan(['script' => [PERM1 => [step(200, page(''), ['content-type' => 'TEXT/HTML; charset=utf-8'])]]]);
expect_state('tipo HTML maiuscolo', $r, 'ok', null, 1);

$r = run_scan(['script' => [PERM1 => [step(200, page(''), [])]]]);
expect_state('Content-Type assente', $r, 'failed', 'unsupported_response', 1);

// ---------------------------------------------------------------------------
// 6. Charset e UTF-8
// ---------------------------------------------------------------------------

$r = ok_page(page(''), ['Content-Type' => 'text/html; charset=ISO-8859-1']);
expect_state('charset HTTP non supportato', $r, 'failed', 'unsupported_response', 1);

$r = ok_page(page('', '<main></main>', false) . '', ['Content-Type' => 'text/html']);
expect_state('UTF-8 senza dichiarazioni', $r, 'ok', null, 1);

$r = run_scan(['script' => [PERM1 => [step(200, str_replace('<meta charset="utf-8">', '<meta charset="windows-1252">', page('')), ['Content-Type' => 'text/html'])]]]);
expect_state('meta charset non UTF-8', $r, 'failed', 'unsupported_response', 1);

$r = run_scan(['script' => [PERM1 => [step(200, page('', '<main></main>', false) . '', ['Content-Type' => 'text/html; charset=utf-8'])]]]);
expect_state('header UTF-8 senza meta', $r, 'ok', null, 1);

$conflict = '<!doctype html><html><head><meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1"><title>x</title></head><body></body></html>';
$r = run_scan(['script' => [PERM1 => [step(200, $conflict, ['Content-Type' => 'text/html; charset=utf-8'])]]]);
expect_state('conflitto header UTF-8 e meta latin1', $r, 'failed', 'unsupported_response', 1);

$r = run_scan(['script' => [PERM1 => [step(200, "<!doctype html><html><head><title>\xC3\x28</title></head><body></body></html>", ['Content-Type' => 'text/html'])]]]);
expect_state('UTF-8 malformato', $r, 'failed', 'invalid_html', 1);

$r = run_scan(['script' => [PERM1 => [step(200, '', ['Content-Type' => 'text/html'])]]]);
expect_state('corpo vuoto con 200', $r, 'failed', 'invalid_html', 1);

$bom = "\xEF\xBB\xBF" . page('<link rel="canonical" href="https://example.test/pagina-uno/">');
$r = run_scan(['script' => [PERM1 => [step(200, $bom, ['Content-Type' => 'text/html'])]]]);
expect_state('BOM UTF-8 rimosso dalla copia di parsing', $r, 'ok', null, 1);
if ($r['snap'] instanceof ObservedSnapshot) {
    check('BOM: hash sui byte originali', $r['snap']->bodySha256() === hash('sha256', $bom));
    check('BOM: canonical letto', values_of($r['snap'], 'canonical')['value'] === ['https://example.test/pagina-uno/']);
}

$caffe = 'https://example.test/caffè/';
$r = run_scan(['script' => [PERM1 => [step(200, page('<link rel="canonical" href="' . $caffe . '">', '<main></main>', false), ['Content-Type' => 'text/html; charset=UTF-8'])]]]);
expect_state('canonical non ASCII con header UTF-8', $r, 'ok', null, 1);
if ($r['snap'] instanceof ObservedSnapshot) {
    check('URL non ASCII intatto', values_of($r['snap'], 'canonical')['value'] === [$caffe]);
}

$r = run_scan(['dom' => false, 'script' => [PERM1 => [step(200, page(''), ['Content-Type' => 'text/html'])]]]);
expect_state('DOM mancante', $r, 'failed', 'parser_unavailable', 1);

// ---------------------------------------------------------------------------
// 7. Estrazione canonical, robots, JSON-LD e posizioni
// ---------------------------------------------------------------------------

$r = ok_page(page('<link rel="canonical" href="https://example.test/pagina-uno/">'));
check('canonical assoluto', values_of($r['snap'], 'canonical') === ['availability' => 'present', 'value' => ['https://example.test/pagina-uno/']]);
check('posizione canonical head', $r['snap']->observedPositions()['canonical'] === ['head']);

$r = ok_page(page('<link rel="CANONICAL alternate" href="/it/?x=1">'));
check('canonical relativo con query e token rel', values_of($r['snap'], 'canonical')['value'] === ['https://example.test/it/?x=1']);

$r = ok_page(page('<link rel="canonical" href="../it/">'));
check('canonical relativo con risalita', values_of($r['snap'], 'canonical')['value'] === ['https://example.test/it/']);

$r = ok_page(page('<link rel="canonical" href="/a/b"><link rel="canonical" href="https://example.test/altro/">'));
check('due canonical distinti conservati', values_of($r['snap'], 'canonical')['value'] === ['https://example.test/a/b', 'https://example.test/altro/']);

$r = ok_page(page('<link rel="canonical" href="">'));
check('canonical con href vuoto: unobservable', values_of($r['snap'], 'canonical')['availability'] === 'unobservable');

$r = ok_page(page('<link rel="canonical" href="mailto:x@example.test">'));
check('canonical con schema non HTTP: unobservable', values_of($r['snap'], 'canonical')['availability'] === 'unobservable');

$r = ok_page(page('<template><link rel="canonical" href="https://example.test/inerte/"></template>'));
check('template escluso dal canonical', values_of($r['snap'], 'canonical') === ['availability' => 'absent', 'value' => []]);

$r = ok_page(page('<meta name="robots" content="noindex, nofollow">'));
check('robots meta direttive', values_of($r['snap'], 'robots')['value'] === [
    ['source' => 'meta', 'bot' => 'all', 'directive' => 'noindex'],
    ['source' => 'meta', 'bot' => 'all', 'directive' => 'nofollow'],
]);
check('posizione robots meta head', $r['snap']->observedPositions()['robots'] === ['head', 'head']);

$r = ok_page(page('<meta name="googlebot" content="noindex">'));
check('robots meta per bot', values_of($r['snap'], 'robots')['value'] === [['source' => 'meta', 'bot' => 'googlebot', 'directive' => 'noindex']]);

$r = ok_page(page('<meta name="robots" content="">'));
check('robots meta vuoto: unobservable', values_of($r['snap'], 'robots')['availability'] === 'unobservable');

$r = ok_page(page('<meta name="description" content="noindex">'));
check('meta non robots ignorato', values_of($r['snap'], 'robots')['availability'] === 'absent');

$r = ok_page(page('<template><meta name="robots" content="noindex"></template>'));
check('template escluso dai robots', values_of($r['snap'], 'robots')['availability'] === 'absent');

$r = ok_page(page(''), ['X-Robots-Tag' => 'noindex']);
check('robots header singolo', values_of($r['snap'], 'robots')['value'] === [['source' => 'header', 'bot' => 'all', 'directive' => 'noindex']]);
check('posizione robots header', $r['snap']->observedPositions()['robots'] === ['http_header']);
check('X-Robots-Tag conservato', $r['snap']->xRobotsTag() === ['noindex']);

$r = ok_page(page(''), ['X-Robots-Tag' => ['noindex', 'googlebot: nofollow']]);
check('X-Robots-Tag ripetuto come lista', values_of($r['snap'], 'robots')['value'] === [
    ['source' => 'header', 'bot' => 'all', 'directive' => 'noindex'],
    ['source' => 'header', 'bot' => 'googlebot', 'directive' => 'nofollow'],
]);

$r = run_scan(['script' => [PERM1 => [step(200, page(''), ['Content-Type' => 'text/html', 'x-robots-tag' => new ArrayObject(['v' => 'noindex, googlebot: none'])])]]]);
check('X-Robots-Tag come dizionario (valore unito)', values_of($r['snap'], 'robots')['value'] === [
    ['source' => 'header', 'bot' => 'all', 'directive' => 'noindex'],
    ['source' => 'header', 'bot' => 'googlebot', 'directive' => 'none'],
]);

$r = ok_page('<!doctype html><html><head><div></div><link rel="canonical" href="https://example.test/x/"><meta name="robots" content="noindex"></head><body><main></main></body></html>');
check('div prima dei tag in head: outside_head', $r['snap'] instanceof ObservedSnapshot
    && $r['snap']->observedPositions()['canonical'] === ['outside_head']
    && $r['snap']->observedPositions()['robots'] === ['outside_head']);

$r = ok_page('<!doctype html><html><head><iframe></iframe><link rel="canonical" href="https://example.test/x/"><meta name="robots" content="noindex"></head><body><main></main></body></html>');
check('iframe prima dei tag in head: outside_head', $r['snap'] instanceof ObservedSnapshot
    && $r['snap']->observedPositions()['canonical'] === ['outside_head']
    && $r['snap']->observedPositions()['robots'] === ['outside_head'],
    $r['snap'] instanceof ObservedSnapshot ? 'osservato canonical=' . json_encode($r['snap']->observedPositions()['canonical']) . ' robots=' . json_encode($r['snap']->observedPositions()['robots']) : 'nessuno snapshot');

$r = ok_page('<!doctype html><html><head><div></div><iframe></iframe><link rel="canonical" href="https://example.test/x/"><meta name="robots" content="noindex"></head><body><main></main></body></html>');
check('div e iframe prima di head: outside_head', $r['snap']->observedPositions()['canonical'] === ['outside_head']
    && $r['snap']->observedPositions()['robots'] === ['outside_head']);

$r = ok_page('<!doctype html><html><head><meta charset="utf-8"><title>x</title></head><body><p>nessun tag</p></body></html>');
check('documento completo senza tag: absent', values_of($r['snap'], 'canonical')['availability'] === 'absent'
    && values_of($r['snap'], 'robots')['availability'] === 'absent'
    && values_of($r['snap'], 'jsonld')['availability'] === 'absent');

$r = ok_page(page('<script type="application/ld+json">{"@type":"WebSite"}</script>'));
check('JSON-LD presente: unobservable fino a S3', values_of($r['snap'], 'jsonld')['availability'] === 'unobservable');

$r = ok_page(page('<template><script type="application/ld+json">{}</script></template>'));
check('JSON-LD in template escluso', values_of($r['snap'], 'jsonld')['availability'] === 'absent');

$r = ok_page(page('', '<main></main>'), ['Age' => '12', 'ETag' => 'W/"abc"', 'Vary' => 'Accept-Encoding', 'Set-Cookie' => 'sid=' . SENTINEL_COOKIE]);
check('cache allowlist', $r['snap'] instanceof ObservedSnapshot && $r['snap']->cacheHeaders() === ['age' => '12', 'etag' => 'W/"abc"', 'vary' => 'Accept-Encoding']);
assert_no_sentinel('cookie fuori allowlist', $r, SENTINEL_COOKIE);

$body = page('<link rel="canonical" href="https://example.test/pagina-uno/">');
$r = run_scan(['script' => [PERM1 => [step(200, $body, ['Content-Type' => 'text/html'])]]]);
check('hash del body ricevuto', $r['snap'] instanceof ObservedSnapshot && $r['snap']->bodySha256() === hash('sha256', $body));
check('timestamp UTC', $r['snap'] instanceof ObservedSnapshot && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $r['snap']->capturedAtUtc()) === 1);
check('URL richiesto dal core', $r['snap'] instanceof ObservedSnapshot && $r['snap']->requestedUrl() === PERM1);

// ---------------------------------------------------------------------------
// 8. Contratti: round trip del report e validazione dei nuovi campi
// ---------------------------------------------------------------------------

$r = ok_page(page('<link rel="canonical" href="https://example.test/pagina-uno/">'), ['Age' => '3']);
if ($r['snap'] instanceof ObservedSnapshot) {
    $report = report_for($r['snap']);
    $again = new ScanReport($report->toArray());
    check('round trip ScanReport', $again->toArray() === $report->toArray());
    $a = $r['snap']->toArray();
    $a['observed_positions'] = ['canonical' => ['head', 'head']];
    $threw = false;
    try {
        new ObservedSnapshot($a);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    check('posizioni non allineate rifiutate', $threw);
    $a = $r['snap']->toArray();
    $a['capture_reason'] = 'Bad Reason';
    $threw = false;
    try {
        new ObservedSnapshot($a);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    check('motivo di capture non valido rifiutato', $threw);
}

// ---------------------------------------------------------------------------
// Esito
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// 9. Robustezza: dato di rete non attendibile, eccezioni, DOM isolato, stato libxml
// ---------------------------------------------------------------------------

foreach ([0, 999] as $code) {
    $r = run_scan(['script' => [PERM1 => [step($code, '')]]]);
    expect_state('codice HTTP fuori intervallo ' . $code, $r, 'failed', 'http_status_unexpected', 1);
}

$r = run_scan(['script' => [PERM1 => [['raw' => ['headers' => []]]]]]);
expect_state('risposta senza chiave response', $r, 'failed', 'http_status_unexpected', 1);

$r = run_scan(['script' => [PERM1 => [['raw' => null]]]]);
expect_state('risposta non array', $r, 'failed', 'transport_error', 1);

$r = run_scan(['script' => [PERM1 => [['raw' => ['response' => ['code' => '200'], 'headers' => ['Content-Type' => 'text/html'], 'body' => '']]]]]);
expect_state('codice HTTP come stringa numerica con corpo vuoto', $r, 'failed', 'invalid_html', 1);

$r = run_scan(['script' => [PERM1 => [['raw' => ['response' => ['code' => '200'], 'headers' => ['Content-Type' => 'text/html']]]]]]);
expect_state('codice HTTP come stringa numerica', $r, 'failed', 'invalid_html', 1);

$r = run_scan(['script' => [PERM1 => [['throw' => 'SENTINEL-EXC-3c9d']]]]);
check('eccezione del trasporto propagata', $r['error'] !== null && strpos((string) $r['error'], 'SENTINEL-EXC-3c9d') !== false, (string) $r['error']);

$r = run_scan(['script' => [PERM1 => [step(301, '', ['Location' => '/a/'])], 'https://example.test/a/' => [['throw' => 'SENTINEL-EXC-7e21']]]]);
check('eccezione al secondo hop propagata', $r['error'] !== null && strpos((string) $r['error'], 'SENTINEL-EXC-7e21') !== false, (string) $r['error']);

// DOM isolato: entita' esterna che punta a un file sintetico con contenuto riconoscibile.
$entityFile = $TEMP_DIR . '/entity-sentinel.txt';
file_put_contents($entityFile, 'https://example.test/SENTINEL-ENT-5d1e/');
$entityBody = '<!DOCTYPE html [<!ENTITY ext SYSTEM "file://' . $entityFile . '">]>'
    . '<html><head><meta charset="utf-8"><title>x</title><link rel="canonical" href="&ext;"></head><body></body></html>';
$r = ok_page($entityBody);
if ($r['snap'] instanceof ObservedSnapshot) {
    check('entita esterna non caricata', $r['json'] !== null && strpos((string) $r['json'], 'SENTINEL-ENT-5d1e') === false,
        'canonical osservato: ' . json_encode($r['snap']->observedValue('canonical')->value()));
} else {
    check('entita esterna: snapshot prodotto', false, (string) $r['error']);
}
@unlink($entityFile);

// Stato libxml: ripristinato identico al valore precedente, partendo da true e da false.
foreach ([true, false] as $before) {
    $previous = libxml_use_internal_errors($before);
    ok_page(page('<link rel="canonical" href="https://example.test/pagina-uno/">'));
    $after = libxml_use_internal_errors($previous);
    check('stato libxml ripristinato partendo da ' . var_export($before, true), $after === $before,
        'dopo la cattura: ' . var_export($after, true));
}

// ---------------------------------------------------------------------------
// 10. Riesame S-002: regressioni R-1..R-4, M-1..M-3 e prove T-1..T-5, M-4 (tutte dal percorso da ID)
// ---------------------------------------------------------------------------

/** DNS che fa avanzare l'orologio durante la risoluzione (residuo dopo il preflight). */
final class AdvancingResolver implements AddressResolver
{
    /** @var float */
    private $advance;

    public function __construct(float $advance)
    {
        $this->advance = $advance;
    }

    public function resolve(string $host): array
    {
        $GLOBALS['clock'] += $this->advance;
        return ['93.184.216.34'];
    }
}

/** Intestazioni come WordPress: iterabili, con valore stringa per header singolo e array per i ripetuti. */
final class FakeHeaders implements IteratorAggregate
{
    /** @var array */
    private $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}

function fam_of(array $run, string $family): array
{
    return $run['snap'] instanceof ObservedSnapshot
        ? values_of($run['snap'], $family)
        : ['availability' => 'nessuno snapshot', 'value' => null];
}

function pos_of(array $run, string $family)
{
    if (!$run['snap'] instanceof ObservedSnapshot) {
        return 'nessuno snapshot';
    }
    $positions = $run['snap']->observedPositions() ?? [];
    return $positions[$family] ?? null;
}

// R-1: residuo di budget sotto 1 ms. Nessuna GET e timeout, anche dopo il preflight DNS e prima del trasporto.
$r = run_scan([
    'resolver' => new AdvancingResolver(4.9996),
    'script' => [PERM1 => [step(200, page(''), ['Content-Type' => 'text/html'])]],
]);
expect_state('R-1 residuo 0,0004 s dopo preflight DNS', $r, 'failed', 'timeout', 0);

$r = run_scan(['script' => [
    PERM1 => [step(301, '', ['Location' => '/a/'], 4.9996)],
    'https://example.test/a/' => [step(200, page(''), ['Content-Type' => 'text/html'])],
]]);
expect_state('R-1 residuo 0,0004 s al secondo hop', $r, 'failed', 'timeout', 1);

// R-2: una dichiarazione vuota rende ambigua l'intera famiglia, anche con una valida accanto.
$r = ok_page(page('<link rel="canonical" href="/a/"><link rel="canonical" href="">'));
check('R-2 canonical vuoto accanto a valido: unobservable', fam_of($r, 'canonical')['availability'] === 'unobservable');
check('R-2 canonical ambiguo senza posizioni', pos_of($r, 'canonical') === []);

$r = ok_page(page('<link rel="canonical" href="/a/"><meta name="robots" content="noindex"><meta name="robots" content="">'));
check('R-2 robots vuoto accanto a valido: unobservable', fam_of($r, 'robots')['availability'] === 'unobservable');
check('R-2 robots ambiguo senza posizioni', pos_of($r, 'robots') === []);
check('R-2 canonical indipendente ancora valutabile', fam_of($r, 'canonical') === ['availability' => 'present', 'value' => ['https://example.test/a/']]);

$r = ok_page(page('<meta name="robots" content="">'), ['X-Robots-Tag' => 'noindex']);
check('R-2 robots vuoto con header valido: unobservable', fam_of($r, 'robots')['availability'] === 'unobservable');

// R-3: il canonical relativo si risolve contro il primo base[href] (DOM), mai contro il path della risposta.
$r = ok_page(page('<base href="https://example.test/other/"><link rel="canonical" href="x/">'));
expect_state('R-3 base assoluta', $r, 'ok', null, 1);
check('R-3 base assoluta: canonical risolto contro base', fam_of($r, 'canonical') === ['availability' => 'present', 'value' => ['https://example.test/other/x/']]);

$r = ok_page(page('<base href="/other/"><link rel="canonical" href="x/">'));
expect_state('R-3 base relativa', $r, 'ok', null, 1);
check('R-3 base relativa risolta contro URL finale', fam_of($r, 'canonical') === ['availability' => 'present', 'value' => ['https://example.test/other/x/']]);

$r = ok_page(page('<base href="https://example.test/first/"><base href="https://example.test/second/"><link rel="canonical" href="x/">'));
check('R-3 il primo base vince', fam_of($r, 'canonical') === ['availability' => 'present', 'value' => ['https://example.test/first/x/']]);

$r = ok_page(page('<base href="mailto:x@example.test"><link rel="canonical" href="x/">'));
check('R-3 base non interpretabile con riferimento relativo: unobservable', fam_of($r, 'canonical')['availability'] === 'unobservable');

$r = ok_page(page('<base href="mailto:x@example.test"><link rel="canonical" href="https://example.test/abs/">'));
check('R-3 base non interpretabile con riferimento assoluto: present', fam_of($r, 'canonical') === ['availability' => 'present', 'value' => ['https://example.test/abs/']]);

// R-4: il candidato JSON-LD si riconosce dall'essenza del MIME, prima di ';'.
$r = ok_page(page('<script type="application/ld+json; charset=utf-8">{}</script>'));
check('R-4 JSON-LD con parametri MIME: unobservable', fam_of($r, 'jsonld')['availability'] === 'unobservable');

$r = ok_page(page('<script type=" Application/LD+JSON ">{}</script>'));
check('R-4 JSON-LD con maiuscole e spazi: unobservable', fam_of($r, 'jsonld')['availability'] === 'unobservable');

$r = ok_page(page('<script type="text/javascript">var a = 1;</script>'));
check('R-4 script di altro tipo: absent', fam_of($r, 'jsonld') === ['availability' => 'absent', 'value' => []]);

// M-1: prefisso IPv6 di documentazione 3fff::/20 non pubblico (RFC 9637).
foreach (['3fff::1' => false, '3fff:fff::1' => false, '3fff:1000::1' => true] as $ip => $expected) {
    check('M-1 classificatore IP ' . $ip, IpClassifier::isPublic($ip) === $expected, 'atteso ' . var_export($expected, true));
}

// M-2: redirect e 401/403/429 classificati prima del controllo su Content-Encoding.
$r = run_scan(['script' => [PERM1 => [step(403, 'bloccato', ['Content-Encoding' => 'gzip'])]]]);
expect_state('M-2 403 con Content-Encoding gzip', $r, 'blocked', 'http_blocked', 1);

$r = run_scan(['script' => [PERM1 => [step(429, '', ['Content-Encoding' => 'br'])]]]);
expect_state('M-2 429 con Content-Encoding br', $r, 'blocked', 'http_blocked', 1);

$r = run_scan(['script' => [
    PERM1 => [step(301, '', ['Location' => '/a/', 'Content-Encoding' => 'gzip'])],
    'https://example.test/a/' => [step(200, page(''), ['Content-Type' => 'text/html'])],
]]);
expect_state('M-2 redirect con Content-Encoding gzip', $r, 'ok', null, 2);

// M-3: schema maiuscolo accettato nel valore canonical, normalizzato solo lo schema.
$r = ok_page(page('<link rel="canonical" href="HTTPS://Example.test/Pagina/">'));
expect_state('M-3 canonical con schema maiuscolo', $r, 'ok', null, 1);
check('M-3 solo lo schema normalizzato', fam_of($r, 'canonical') === ['availability' => 'present', 'value' => ['https://Example.test/Pagina/']]);

// Guardia M-3: il Location con schema maiuscolo resta fuori dal target di cattura (parsing separato).
$r = run_scan(['script' => [PERM1 => [step(301, '', ['Location' => 'HTTPS://example.test/a/'])]]]);
expect_state('guardia Location con schema maiuscolo rifiutata', $r, 'blocked', 'redirect_rejected', 1);

// T-1: ogni hop porta il contratto di trasporto completo.
$r = run_scan(['script' => [
    PERM1 => [step(301, '', ['Location' => '/a/'])],
    'https://example.test/a/' => [step(200, page(''), ['Content-Type' => 'text/html'])],
]]);
check('T-1 due hop osservati', count($r['calls']) === 2);
foreach ($r['calls'] as $call) {
    $a = $call['args'];
    check('T-1 sslverify true', ($a['sslverify'] ?? null) === true);
    check('T-1 redirection 0', ($a['redirection'] ?? null) === 0);
    check('T-1 decompress false', ($a['decompress'] ?? null) === false);
    check('T-1 stream true', ($a['stream'] ?? null) === true);
    check('T-1 filename non vuoto', isset($a['filename']) && is_string($a['filename']) && $a['filename'] !== '');
    check('T-1 limit_response_size cap+1', ($a['limit_response_size'] ?? null) === CAP + 1);
}

// T-2: costruttore senza soglia esplicita, 1 MiB con sentinella; esattamente il cap resta ok.
$big = page('') . str_repeat(' ', CAP + 10 - strlen(page('')));
$r = run_scan(['default_limit' => true, 'script' => [PERM1 => [step(200, $big, ['Content-Type' => 'text/html'])]]]);
expect_state('T-2 default oltre 1 MiB', $r, 'partial', 'body_limit', 1);
check('T-2 default: sentinella a CAP+1', ($r['calls'][0]['args']['limit_response_size'] ?? null) === CAP + 1);

$exact = page('<link rel="canonical" href="https://example.test/pagina-uno/">');
$exact .= str_repeat(' ', CAP - strlen($exact));
$r = run_scan(['default_limit' => true, 'script' => [PERM1 => [step(200, $exact, ['Content-Type' => 'text/html'])]]]);
expect_state('T-2 default esattamente al cap', $r, 'ok', null, 1);

// T-3: intestazioni iterabili come CaseInsensitiveDictionary di WordPress.
$r = run_scan(['script' => [PERM1 => [[
    'code' => 200,
    'body' => page(''),
    'headers' => new FakeHeaders(['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => ['noindex', 'googlebot: nofollow']]),
    'advance' => 0.0,
]]]]);
expect_state('T-3 intestazioni iterabili', $r, 'ok', null, 1);
check('T-3 robots da header iterabili', fam_of($r, 'robots')['value'] === [
    ['source' => 'header', 'bot' => 'all', 'directive' => 'noindex'],
    ['source' => 'header', 'bot' => 'googlebot', 'directive' => 'nofollow'],
]);
check('T-3 X-Robots-Tag ripetuto conservato', $r['snap'] instanceof ObservedSnapshot && $r['snap']->xRobotsTag() === ['noindex', 'googlebot: nofollow']);

// T-4: status catturato; corpo, Set-Cookie e header fuori allowlist assenti dal report.
$body4 = page('<link rel="canonical" href="https://example.test/pagina-uno/">', '<main>SENTINEL-BODY-5c2d</main>');
$r = run_scan(['script' => [PERM1 => [step(200, $body4, ['Content-Type' => 'text/html', 'Set-Cookie' => 'sid=' . SENTINEL_COOKIE, 'X-Non-Allowlist' => 'SENTINEL-HDR-9a4e'])]]]);
expect_state('T-4 risposta 200', $r, 'ok', null, 1);
check('T-4 status HTTP 200', $r['snap'] instanceof ObservedSnapshot && $r['snap']->httpStatus() === 200);
assert_no_sentinel('T-4 corpo', $r, 'SENTINEL-BODY-5c2d');
assert_no_sentinel('T-4 Set-Cookie', $r, SENTINEL_COOKIE);
assert_no_sentinel('T-4 header fuori allowlist', $r, 'SENTINEL-HDR-9a4e');

// T-5: round trip del report con posizioni, cache e motivo di capture, letti dopo la ricostruzione.
$r = ok_page(page('<link rel="canonical" href="/a/">'), ['Age' => '12', 'ETag' => 'W/"abc"', 'Vary' => 'Accept-Encoding']);
if ($r['snap'] instanceof ObservedSnapshot) {
    $again = (new ScanReport(report_for($r['snap'])->toArray()))->toArray();
    check('T-5 posizioni dopo round trip', ($again['observed_positions']['canonical'] ?? null) === ['head']);
    check('T-5 cache dopo round trip', ($again['http']['cache_headers'] ?? null) === ['age' => '12', 'etag' => 'W/"abc"', 'vary' => 'Accept-Encoding']);
}
$r = run_scan(['script' => [PERM1 => [step(403, '')]]]);
if ($r['snap'] instanceof ObservedSnapshot) {
    $again = (new ScanReport(report_for($r['snap'])->toArray()))->toArray();
    check('T-5 capture_reason dopo round trip', ($again['capture_reason'] ?? null) === 'http_blocked');
    check('T-5 capture_status dopo round trip', ($again['capture_status'] ?? null) === 'blocked');
}

// M-4: risposta parziale (206) con tutte e tre le famiglie unobservable.
$r = run_scan(['script' => [PERM1 => [step(206, page('<link rel="canonical" href="https://example.test/pagina-uno/"><meta name="robots" content="noindex"><script type="application/ld+json">{}</script>'), ['Content-Type' => 'text/html'])]]]);
expect_state('M-4 risposta 206', $r, 'partial', 'partial_content', 1);
check('M-4 canonical unobservable', fam_of($r, 'canonical')['availability'] === 'unobservable');
check('M-4 robots unobservable', fam_of($r, 'robots')['availability'] === 'unobservable');
check('M-4 jsonld unobservable', fam_of($r, 'jsonld')['availability'] === 'unobservable');

if (is_dir($TEMP_DIR)) {
    @rmdir($TEMP_DIR);
}

if ($failures !== []) {
    fwrite(STDERR, "FALLITI " . count($failures) . " controlli (superati " . $passed . "):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo 'S1 capture: ' . $passed . " controlli superati, 0 falliti\n";
exit(0);
