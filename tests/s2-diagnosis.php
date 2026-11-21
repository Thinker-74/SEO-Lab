<?php

declare(strict_types=1);

/*
 * Verificatore S2 di SEO Lab: Yoast expected (SeoLab\Expected), capture Link canonical (SeoLab\Capture),
 * regole canonical/robots (SeoLab\Rules) e diagnosi (SeoLab\Diagnosis). Uso: php tests/s2-diagnosis.php
 * Uscita 0 solo se tutti i controlli passano. Trasporto HTTP, DNS, funzioni WordPress e Surfaces Yoast sono
 * stub controllati: nessuna rete reale. Ogni warning, notice o deprecation e' un errore.
 * Gli esiti attesi delle fixture 01-06 e 10 sono l'oracolo: il verificatore non li ricalcola.
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
        'SeoLab\\Expected\\' => 'src/Expected/',
        'SeoLab\\Rules\\' => 'src/Rules/',
        'SeoLab\\Diagnosis\\' => 'src/Diagnosis/',
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
use SeoLab\Capture\SafeHttpCapturer;
use SeoLab\Contracts\ExpectedProvider;
use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\ScanReport;
use SeoLab\Diagnosis\CanonicalRobotsDiagnoser;
use SeoLab\Expected\YoastExpectedProvider;

// ---------------------------------------------------------------------------
// Costanti e nomi delle regole
// ---------------------------------------------------------------------------

const BASE = 'https://example.test/pagina/';
const ALT = 'https://example.test/altra/';
const POST = 1;
const CAP = 1048576;
const SCAN_UUID = '00000000-0000-4000-8000-0000000002a1';
const M = 'CANONICAL_MULTIPLE_DISTINCT';
const X = 'CANONICAL_EXPECTED_MISMATCH';
const S = 'CANONICAL_MISSING_WHEN_EXPECTED';
const E = 'ROBOTS_EFFECTIVE_MISMATCH';
const U = 'ROBOTS_UNEXPECTED_NOINDEX';

$argvList = $argv ?? [];

// ---------------------------------------------------------------------------
// Stub WordPress (namespace globale): core per la selezione e trasporto con risposte pre-programmate.
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
    return 'https://example.test/';
}

function wp_safe_remote_get($url, $args = [])
{
    $GLOBALS['net']['calls'][] = $url;
    $queue = $GLOBALS['net']['script'][$url] ?? [];
    if ($queue === []) {
        $GLOBALS['net']['violations'][] = 'richiesta non prevista: ' . $url;
        return new WP_Error('unscripted', 'richiesta non prevista');
    }
    $step = array_shift($GLOBALS['net']['script'][$url]);
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
// Stub Yoast: Surfaces con accesso dinamico alle proprieta' (come Yoast\WP\SEO\Surfaces\Values\Meta).
// ---------------------------------------------------------------------------

final class FakeMeta
{
    /** @var array<string, mixed> */
    private $props;

    public function __construct(array $props)
    {
        $this->props = $props;
    }

    public function __isset($name): bool
    {
        return isset($this->props[$name]);
    }

    public function __get($name)
    {
        $value = $this->props[$name] ?? null;
        if ($value instanceof \Throwable) {
            throw $value;
        }
        return $value;
    }
}

// Versione Yoast verificata per i casi con Yoast presente (il child senza Yoast non la legge).
define('WPSEO_VERSION', '28.9');

eval('namespace Yoast\\WP\\SEO\\Models; class Indexable { public $object_id; public $object_type; public $version = 1; }');

function yoast_indexable(int $id, string $type = 'post'): object
{
    $indexable = new \Yoast\WP\SEO\Models\Indexable();
    $indexable->object_id = $id;
    $indexable->object_type = $type;
    return $indexable;
}

final class FakeMetaSurface
{
    /** @var mixed */
    public $result = false;

    /** @var int */
    public $calls = 0;

    /** @var string|null */
    public $lastPageType = null;

    public function for_indexable($indexable, $pageType = null)
    {
        $this->calls++;
        $this->lastPageType = $pageType;
        if ($this->result instanceof \Throwable) {
            throw $this->result;
        }
        return $this->result;
    }

    /** Percorso vietato in S2: se viene chiamato, il caso fallisce anche se l'eccezione viene isolata. */
    public function for_post($id)
    {
        $GLOBALS['yoast_violations'][] = 'for_post';
        throw new \LogicException('for_post vietato in S2');
    }
}

final class FakeIndexableQuery
{
    /** @var FakeIndexableRepository */
    private $repo;

    /** @var array<string, mixed> */
    private $where = [];

    public function __construct(FakeIndexableRepository $repo)
    {
        $this->repo = $repo;
    }

    public function where($column, $value)
    {
        $this->where[$column] = $value;
        return $this;
    }

    public function find_one()
    {
        $this->repo->lookups++;
        $this->repo->lastWhere = $this->where;
        if ($this->repo->found instanceof \Throwable) {
            throw $this->repo->found;
        }
        return $this->repo->found;
    }
}

final class FakeIndexableRepository
{
    /** @var mixed Indexable, false (assente) o eccezione */
    public $found = false;

    /** @var int */
    public $lookups = 0;

    /** @var array<string, mixed> */
    public $lastWhere = [];

    public function query()
    {
        return new FakeIndexableQuery($this);
    }
}

final class FakeVersionManager
{
    /** @var bool */
    public $needsUpgrade = false;

    /** @var int */
    public $calls = 0;

    public function indexable_needs_upgrade($indexable): bool
    {
        $this->calls++;
        return $this->needsUpgrade;
    }
}

final class FakeClasses
{
    /**
     * Solo i servizi previsti da YoastIndexableReader: un nome diverso e' un errore di test.
     */
    public function get($name)
    {
        $yoast = $GLOBALS['yoast'];
        if ($name === 'Yoast\\WP\\SEO\\Repositories\\Indexable_Repository') {
            return $yoast->repository;
        }
        if ($name === 'Yoast\\WP\\SEO\\Services\\Indexables\\Indexable_Version_Manager') {
            return $yoast->versions;
        }
        throw new \RuntimeException('servizio Yoast non previsto: ' . $name);
    }
}

final class FakeYoastMain
{
    /** @var FakeMetaSurface */
    public $meta;

    /** @var FakeClasses */
    public $classes;

    /** @var FakeIndexableRepository */
    public $repository;

    /** @var FakeVersionManager */
    public $versions;

    public function __construct()
    {
        $this->meta = new FakeMetaSurface();
        $this->classes = new FakeClasses();
        $this->repository = new FakeIndexableRepository();
        $this->versions = new FakeVersionManager();
    }
}

if (!in_array('--yoast-absent-child', $argvList, true)) {
    $GLOBALS['yoast'] = new FakeYoastMain();
    $GLOBALS['yoast_violations'] = [];
    function YoastSEO()
    {
        return $GLOBALS['yoast'];
    }
}

/**
 * Converte direttive nel formato array di Yoast: chiave = nome della direttiva (senza "no", senza valore).
 *
 * @param string[] $directives
 * @return array<string, string>
 */
function yoast_robots(array $directives): array
{
    $out = [];
    foreach ($directives as $directive) {
        $key = strpos($directive, 'no') === 0 ? substr($directive, 2) : $directive;
        $colon = strpos($key, ':');
        if ($colon !== false) {
            $key = substr($key, 0, $colon);
        }
        $out[$key] = $directive;
    }
    return $out;
}

/**
 * Configurazione Yoast per un caso. Valori stringa: 'none' (indexable assente), 'throw' (repository eccezione),
 * 'obsolete' (versione inferiore al builder), 'wrongtype' (indexable di tipo term), 'nometa' (for_indexable
 * false). Array: indexable corrente con canonical, robots, googlebot; i valori 'MISSING' e 'THROW' omettono la
 * proprieta' o la fanno lanciare.
 *
 * @param mixed $spec
 */
function yoast_configure($spec, int $postId): void
{
    $yoast = $GLOBALS['yoast'];
    $yoast->meta->calls = 0;
    $yoast->meta->lastPageType = null;
    $yoast->meta->result = false;
    $yoast->repository->lookups = 0;
    $yoast->repository->lastWhere = [];
    $yoast->repository->found = false;
    $yoast->versions->calls = 0;
    $yoast->versions->needsUpgrade = false;
    if ($spec === 'none') {
        return;
    }
    if ($spec === 'throw') {
        $yoast->repository->found = new \RuntimeException('Yoast: errore simulato');
        return;
    }
    if ($spec === 'obsolete') {
        $yoast->repository->found = yoast_indexable($postId);
        $yoast->versions->needsUpgrade = true;
        return;
    }
    if ($spec === 'wrongtype') {
        $yoast->repository->found = yoast_indexable($postId, 'term');
        return;
    }
    $yoast->repository->found = yoast_indexable($postId);
    if ($spec === 'nometa') {
        return;
    }
    $surface = $yoast->meta;
    $props = [];
    $canonical = $spec['canonical'] ?? BASE;
    if ($canonical === 'THROW') {
        $props['canonical'] = new \RuntimeException('accesso dinamico fallito');
    } elseif ($canonical !== 'MISSING') {
        $props['canonical'] = $canonical;
    }
    $robots = $spec['robots'] ?? ['index', 'follow'];
    if ($robots === 'THROW') {
        $props['robots'] = new \RuntimeException('accesso dinamico fallito');
    } elseif (is_string($robots)) {
        $props['robots'] = $robots;
    } elseif (is_array($robots) && $robots !== [] && array_keys($robots) !== range(0, count($robots) - 1)) {
        $props['robots'] = $robots;
    } elseif (is_array($robots)) {
        $props['robots'] = yoast_robots($robots);
    }
    if (isset($spec['googlebot'])) {
        $props['googlebot'] = $spec['googlebot'];
    }
    $surface->result = new FakeMeta($props);
}

// ---------------------------------------------------------------------------
// Trasporto DNS e capture
// ---------------------------------------------------------------------------

final class FakeResolver implements AddressResolver
{
    /** @var array<string, string[]> */
    private $table;

    public function __construct(array $table)
    {
        $this->table = $table;
    }

    public function resolve(string $host): array
    {
        return $this->table[$host] ?? [];
    }
}

/** Decora il provider per conservare l'ultimo snapshot risolto e contare le chiamate. */
final class RecordingProvider implements ExpectedProvider
{
    /** @var ExpectedProvider */
    private $inner;

    /** @var ExpectedSnapshot|null */
    private $last;

    /** @var int */
    private $calls = 0;

    public function __construct(ExpectedProvider $inner)
    {
        $this->inner = $inner;
    }

    public function forPost(int $postId): ExpectedSnapshot
    {
        $this->calls++;
        $this->last = $this->inner->forPost($postId);
        return $this->last;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function last(): ExpectedSnapshot
    {
        return $this->last;
    }
}

function step(int $code, string $body = '', array $headers = []): array
{
    return ['code' => $code, 'body' => $body, 'headers' => $headers];
}

function page(string $head, string $body = '<main><h1>Pagina</h1></main>'): string
{
    return '<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Pagina</title>'
        . $head . '</head><body>' . $body . '</body></html>';
}

function link_canonical(string $href): string
{
    return "<link rel='canonical' href='" . $href . "'>";
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failures;
    if ($ok) {
        $passed++;
        return;
    }
    $failures[] = $label . ($detail !== '' ? ' [' . $detail . ']' : '');
}

/**
 * Esegue capture reale e diagnosi reale per un caso. Chiavi di $spec: url, post, post_status, html, headers,
 * status, steps (script completo), body_limit, yoast (vedi yoast_configure).
 */
function diag(array $spec): array
{
    global $TEMP_DIR, $calls_total;
    $url = $spec['url'] ?? BASE;
    $postId = $spec['post'] ?? POST;
    $GLOBALS['wp'] = [
        'posts' => [$postId => (object) ['post_status' => $spec['post_status'] ?? 'publish', 'post_type' => 'page', 'post_password' => '']],
        'permalinks' => [$postId => $url],
    ];
    $headers = $spec['headers'] ?? [];
    $steps = $spec['steps'] ?? [
        $url => [step($spec['status'] ?? 200, $spec['html'] ?? page(''), $headers + ['Content-Type' => 'text/html; charset=UTF-8'])],
    ];
    $GLOBALS['net'] = ['script' => $steps, 'calls' => [], 'violations' => []];
    $GLOBALS['clock'] = 1000.0;

    $capturer = new SafeHttpCapturer(
        new FakeResolver(['example.test' => ['93.184.216.34']]),
        static function (): float {
            return (float) $GLOBALS['clock'];
        },
        $TEMP_DIR,
        true,
        $spec['body_limit'] ?? CAP
    );
    $snap = $capturer->captureForPost($postId);
    check('nessuna richiesta fuori script', $GLOBALS['net']['violations'] === [], implode(';', $GLOBALS['net']['violations']));
    check('temp pulito dopo la cattura', (glob($TEMP_DIR . '/seolab-*') ?: []) === []);

    yoast_configure($spec['yoast'] ?? [], $postId);
    $yoastProvider = new YoastExpectedProvider($spec['yoast_version'] ?? null);
    $recording = new RecordingProvider($yoastProvider);
    $outcomes = (new CanonicalRobotsDiagnoser($recording))->diagnose($postId, $snap);
    $calls_total++;

    $byId = [];
    foreach ($outcomes as $outcome) {
        $byId[$outcome->ruleId()] = $outcome;
    }
    check('cinque esiti in ordine stabile', array_keys($byId) === [M, X, S, E, U] && count($outcomes) === 5);

    $expected = $recording->last();
    $report = scan_report($snap, $outcomes, $expected, $postId);
    $again = new ScanReport($report->toArray());
    check('round trip ScanReport stabile', json_encode($again->toArray()) === json_encode($report->toArray()));

    return [
        'snap' => $snap,
        'byId' => $byId,
        'expected' => $expected,
        'provider_calls' => $recording->calls(),
        'surface_calls' => $GLOBALS['yoast']->meta->calls,
        'page_type' => $GLOBALS['yoast']->meta->lastPageType,
        'lookups' => $GLOBALS['yoast']->repository->lookups,
        'lookup_where' => $GLOBALS['yoast']->repository->lastWhere,
        'version_checks' => $GLOBALS['yoast']->versions->calls,
        'reason' => $yoastProvider->lastReason(),
        'report' => $report,
    ];
}

function scan_report(ObservedSnapshot $snap, array $outcomes, ExpectedSnapshot $expected, int $postId): ScanReport
{
    $findings = [];
    $rows = [];
    foreach ($outcomes as $outcome) {
        $rows[] = $outcome->toArray();
        if ($outcome->finding() !== null) {
            $findings[] = $outcome->finding()->toArray();
        }
    }
    return new ScanReport(['scan_id' => SCAN_UUID, 'schema_version' => '1', 'post_id' => $postId,
        'source' => ['provider' => 'yoast', 'provider_version' => 'test'], 'expected' => $expected->toArray(),
        'rule_outcomes' => $rows, 'findings' => $findings] + $snap->toArray());
}

function state_of(array $r, string $rule): string
{
    return isset($r['byId'][$rule]) ? $r['byId'][$rule]->state() : 'missing';
}

/** @param array<string, string> $expect regola => stato */
function expect_states(string $label, array $r, array $expect): void
{
    foreach ($expect as $rule => $state) {
        $actual = state_of($r, $rule);
        check($label . ' ' . $rule, $actual === $state, 'atteso ' . $state . ', ottenuto ' . $actual);
    }
}

function evidence_kinds(RuleOutcome $outcome): array
{
    $kinds = [];
    if ($outcome->finding() !== null) {
        foreach ($outcome->finding()->evidence() as $item) {
            $kinds[] = $item['kind'];
        }
    }
    return $kinds;
}

function family_av(ObservedSnapshot $snap, string $family): string
{
    return $snap->observedValue($family)->availability();
}

// ---------------------------------------------------------------------------
// 0. Child: Yoast assente (la funzione YoastSEO non e' definita in questo processo)
// ---------------------------------------------------------------------------

if (in_array('--yoast-absent-child', $argvList, true)) {
    $GLOBALS['wp'] = [
        'posts' => [POST => (object) ['post_status' => 'publish', 'post_type' => 'page', 'post_password' => '']],
        'permalinks' => [POST => BASE],
    ];
    $absent = (new YoastExpectedProvider())->forPost(POST);
    echo 'canonical=' . $absent->resolved('canonical')->availability()
        . ' robots=' . $absent->resolved('robots')->availability() . "\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

$TEMP_DIR = sys_get_temp_dir() . '/seolab-s2-test-' . getmypid();
if (!is_dir($TEMP_DIR) && !mkdir($TEMP_DIR, 0700, true)) {
    fwrite(STDERR, "impossibile creare la cartella temporanea di test\n");
    exit(2);
}

$passed = 0;
$failures = [];
$calls_total = 0;

// ---------------------------------------------------------------------------
// 1. Fixture 01-06 e 10: oracolo degli esiti preesistenti
// ---------------------------------------------------------------------------

$fixtureDir = __DIR__ . '/fixtures';
$fixtureFiles = ['01', '02', '03', '04', '05', '06', '10'];
$fixturesRead = 0;
$scansRead = 0;
foreach ($fixtureFiles as $id) {
    $files = glob($fixtureDir . '/' . $id . '-*.json') ?: [];
    check('fixture ' . $id . ' presente', count($files) === 1);
    if (count($files) !== 1) {
        continue;
    }
    $fixture = json_decode((string) file_get_contents($files[0]), true);
    check('fixture ' . $id . ' JSON valido', is_array($fixture));
    $fixturesRead++;
    echo 'FIXTURE ' . $id . "\n";
    foreach ($fixture['scans'] as $index => $scan) {
        $scansRead++;
        $label = 'fixture ' . $id . '#' . $index;
        $report = $scan['report'];
        $headers = $scan['input']['headers'] ?? [];
        $hasContentType = false;
        foreach (array_keys($headers) as $name) {
            if (strtolower((string) $name) === 'content-type') {
                $hasContentType = true;
            }
        }
        if (!$hasContentType) {
            $headers['Content-Type'] = $report['http']['content_type'];
        }
        $yoastSpec = [];
        $resolved = $report['expected']['resolved'];
        $yoastSpec['canonical'] = $resolved['canonical']['availability'] === 'available'
            ? $resolved['canonical']['value'] : 'MISSING';
        if ($resolved['robots']['availability'] === 'available') {
            $yoastSpec['robots'] = is_array($resolved['robots']['value'])
                ? $resolved['robots']['value'] : yoast_robots(array_map('trim', explode(',', (string) $resolved['robots']['value'])));
        } else {
            $yoastSpec['robots'] = 'MISSING';
        }
        $r = diag([
            'url' => $report['requested_url'],
            'post' => $report['post_id'],
            'html' => $scan['input']['html'],
            'headers' => $headers,
            'yoast' => $yoastSpec,
        ]);
        check($label . ' capture ok', $r['snap']->status() === 'ok', $r['snap']->status());
        check($label . ' un solo confronto Yoast', $r['provider_calls'] === 1 && $r['surface_calls'] === 1);
        foreach ($report['rule_outcomes'] as $row) {
            $rule = $row['rule_id'];
            $o = $r['byId'][$rule] ?? null;
            check($label . ' ' . $rule . ' esiste', $o !== null);
            if ($o === null) {
                continue;
            }
            check($label . ' ' . $rule . ' stato', $o->state() === $row['state'], 'atteso ' . $row['state'] . ', ottenuto ' . $o->state());
            check($label . ' ' . $rule . ' causa', $o->causeState() === $row['cause_state']);
            check($label . ' ' . $rule . ' versione', $o->ruleVersion() === $row['rule_version']);
        }
        foreach ($report['findings'] as $expectedFinding) {
            $rule = $expectedFinding['rule_id'];
            $o = $r['byId'][$rule] ?? null;
            $f = $o !== null ? $o->finding() : null;
            check($label . ' ' . $rule . ' finding presente', $f !== null);
            if ($f === null) {
                continue;
            }
            check($label . ' ' . $rule . ' severita', $f->severity() === $expectedFinding['severity'], $f->severity());
            check($label . ' ' . $rule . ' versione finding', $f->ruleVersion() === $expectedFinding['rule_version']);
            check($label . ' ' . $rule . ' stato finding', $f->state() === $expectedFinding['state']);
            check($label . ' ' . $rule . ' causa finding', $f->causeState() === $expectedFinding['cause']['state']);
            $expectedKinds = array_map(static function (array $item): string {
                return $item['kind'];
            }, $expectedFinding['evidence']);
            check($label . ' ' . $rule . ' evidenza per tipo', evidence_kinds($o) === $expectedKinds,
                implode(',', evidence_kinds($o)) . ' vs ' . implode(',', $expectedKinds));
        }
        $expectedRules = array_map(static function (array $row): string {
            return $row['rule_id'];
        }, $report['findings']);
        foreach ($r['byId'] as $rule => $o) {
            if ($o->finding() !== null && !in_array($rule, $expectedRules, true)) {
                check($label . ' finding inatteso ' . $rule, false);
            }
        }
    }
}
check('fixture 01-06 e 10 attraversate', $fixturesRead === 7 && $scansRead === 8, $fixturesRead . ' fixture, ' . $scansRead . ' scansioni');

// ---------------------------------------------------------------------------
// 2. Provider Yoast: isolamento, formati e idoneita'
// ---------------------------------------------------------------------------

$r = diag(['yoast' => 'none', 'html' => page(link_canonical(BASE))]);
check('P-1 indexable assente: canonical unavailable', $r['expected']->resolved('canonical')->availability() === 'unavailable');
check('P-1 indexable assente: robots unavailable', $r['expected']->resolved('robots')->availability() === 'unavailable');
check('P-1 jsonld unavailable e configured unavailable', $r['expected']->resolved('jsonld')->availability() === 'unavailable'
    && $r['expected']->configured('canonical')->availability() === 'unavailable');
expect_states('P-1', $r, [M => 'no_anomaly', X => 'inconclusive', S => 'inconclusive', E => 'inconclusive', U => 'inconclusive']);

$r = diag(['yoast' => 'throw', 'html' => page(link_canonical(BASE))]);
check('P-2 eccezione del repository isolata', $r['expected']->resolved('canonical')->availability() === 'unavailable'
    && $r['expected']->resolved('robots')->availability() === 'unavailable');

$r = diag(['yoast' => ['canonical' => 'THROW', 'robots' => ['index', 'follow']], 'html' => page(link_canonical(BASE))]);
check('P-3 canonical che lancia: solo canonical unavailable', $r['expected']->resolved('canonical')->availability() === 'unavailable'
    && $r['expected']->resolved('robots')->availability() === 'available');

$r = diag(['yoast' => ['canonical' => BASE, 'robots' => 'index'], 'html' => page(link_canonical(BASE))]);
check('P-4 robots stringa non array: unavailable', $r['expected']->resolved('robots')->availability() === 'unavailable'
    && $r['expected']->resolved('canonical')->availability() === 'available');

$r = diag(['yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow'], 'googlebot' => ['noindex']], 'html' => page(link_canonical(BASE))]);
check('P-7 googlebot con ambito esplicito', $r['expected']->resolved('robots')->value() === ['index', 'follow', 'googlebot: noindex']);

$r = diag(['yoast' => ['canonical' => '', 'robots' => ['index' => 'noindex', 'follow' => 'follow']], 'html' => page('<meta name="robots" content="noindex">')]);
check('P-5 canonical vuoto esplicito disponibile', $r['expected']->resolved('canonical')->availability() === 'available'
    && $r['expected']->resolved('canonical')->value() === '' && $r['expected']->resolved('canonical')->source() === 'yoast:resolved');

$r = diag(['yoast' => ['canonical' => 'pagina/', 'robots' => ['index', 'follow']], 'html' => page('')]);
check('P-6 canonical relativo non ammesso: unavailable', $r['expected']->resolved('canonical')->availability() === 'unavailable');

$r = diag(['yoast' => ['robots' => []], 'html' => page(link_canonical(BASE))]);
check('P-6b robots array vuoto valido', $r['expected']->resolved('robots')->availability() === 'available'
    && $r['expected']->resolved('robots')->value() === []);

$r = diag(['yoast' => [], 'post_status' => 'draft', 'html' => page(link_canonical(BASE))]);
check('P-8 post non idoneo: nessuna chiamata a Yoast', $r['surface_calls'] === 0 && $r['expected']->resolved('canonical')->availability() === 'unavailable');
check('P-8 post non idoneo: capture blocked', $r['snap']->status() === 'blocked');

// Yoast assente: processo figlio senza funzione YoastSEO.
$output = [];
$code = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --yoast-absent-child 2>&1', $output, $code);
check('P-10 Yoast assente: nessun errore', $code === 0, implode(' | ', $output));
check('P-10 Yoast assente: unavailable', implode('', $output) === 'canonical=unavailable robots=unavailable',
    implode(' | ', $output));

// ---------------------------------------------------------------------------
// 2b. Lettura del solo indexable corrente: motivi distinti, nessun for_post, nessun lookup se non idoneo
// ---------------------------------------------------------------------------

$current = ['canonical' => BASE, 'robots' => ['index', 'follow']];

$r = diag(['yoast' => 'none', 'html' => page(link_canonical(BASE))]);
check('R-1 indexable mancante: motivo', $r['reason'] === 'indexable_missing', $r['reason']);
check('R-1 indexable mancante: un lookup, nessuna verifica o presentazione', $r['lookups'] === 1 && $r['version_checks'] === 0 && $r['surface_calls'] === 0);
check('R-1 indexable mancante: canonical e robots unavailable', $r['expected']->resolved('canonical')->availability() === 'unavailable'
    && $r['expected']->resolved('robots')->availability() === 'unavailable');

$r = diag(['yoast' => 'obsolete', 'html' => page(link_canonical(BASE))]);
check('R-2 indexable obsoleto: motivo', $r['reason'] === 'indexable_obsolete', $r['reason']);
check('R-2 indexable obsoleto: verifica di versione, nessuna presentazione', $r['version_checks'] === 1 && $r['surface_calls'] === 0);
check('R-2 indexable obsoleto: canonical e robots unavailable', $r['expected']->resolved('canonical')->availability() === 'unavailable'
    && $r['expected']->resolved('robots')->availability() === 'unavailable');

$r = diag(['yoast' => 'wrongtype', 'html' => page(link_canonical(BASE))]);
check('R-3 indexable di altro tipo: motivo indexable_invalid', $r['reason'] === 'indexable_invalid', $r['reason']);
check('R-3 indexable di altro tipo: nessuna verifica o presentazione', $r['version_checks'] === 0 && $r['surface_calls'] === 0);

$r = diag(['yoast' => 'throw', 'html' => page(link_canonical(BASE))]);
check('R-4 eccezione del repository: motivo yoast_api_error', $r['reason'] === 'yoast_api_error', $r['reason']);

$r = diag(['yoast' => 'nometa', 'html' => page(link_canonical(BASE))]);
check('R-5 for_indexable false: motivo meta_unavailable', $r['reason'] === 'meta_unavailable' && $r['surface_calls'] === 1, $r['reason']);
check('R-5 for_indexable false: canonical unavailable', $r['expected']->resolved('canonical')->availability() === 'unavailable');

$r = diag(['yoast' => $current, 'yoast_version' => '28.8', 'html' => page(link_canonical(BASE))]);
check('R-6 versione non verificata: motivo', $r['reason'] === 'yoast_version_unverified', $r['reason']);
check('R-6 versione non verificata: nessun lookup, nessuna presentazione', $r['lookups'] === 0 && $r['version_checks'] === 0 && $r['surface_calls'] === 0);
check('R-6 versione non verificata: unavailable senza fallback', $r['expected']->resolved('canonical')->availability() === 'unavailable'
    && $r['expected']->resolved('robots')->availability() === 'unavailable');

$r = diag(['yoast' => $current, 'post_status' => 'draft', 'html' => page(link_canonical(BASE))]);
check('R-7 post non idoneo: motivo not_eligible, nessun lookup', $r['reason'] === 'not_eligible' && $r['lookups'] === 0 && $r['surface_calls'] === 0, $r['reason']);

$r = diag(['yoast' => $current, 'html' => page(link_canonical(BASE))]);
check('R-8 indexable corrente: motivo ok', $r['reason'] === 'ok', $r['reason']);
check('R-8 indexable corrente: lookup solo su object_id e object_type post', $r['lookups'] === 1
    && $r['lookup_where'] === ['object_id' => POST, 'object_type' => 'post'], json_encode($r['lookup_where']));
check('R-8 indexable corrente: una verifica di versione e una presentazione Post_Type', $r['version_checks'] === 1
    && $r['surface_calls'] === 1 && $r['page_type'] === 'Post_Type');
check('R-8 indexable corrente: canonical e robots disponibili', $r['expected']->resolved('canonical')->value() === BASE
    && $r['expected']->resolved('robots')->value() === ['index', 'follow']);
check('R-9 nessuna chiamata a for_post in tutti i casi', $GLOBALS['yoast_violations'] === [], implode(',', $GLOBALS['yoast_violations']));

// ---------------------------------------------------------------------------
// 3. Capture Link canonical e posizioni
// ---------------------------------------------------------------------------

$r = diag(['headers' => ['Link' => ['<' . BASE . '>; rel="canonical"']], 'html' => page('')]);
expect_states('C-A Link corrispondente', $r, [M => 'no_anomaly', X => 'no_anomaly', S => 'no_anomaly', E => 'no_anomaly', U => 'no_anomaly']);
check('C-A canonical da Link in posizione http_header', $r['snap']->observedPositions()['canonical'] === ['http_header']);

$r = diag(['headers' => ['Link' => ['<' . ALT . '>; rel="canonical"']], 'html' => page('')]);
check('C-B Link divergente: mismatch confirmed', state_of($r, X) === 'confirmed_anomaly');
check('C-B evidenza header Link', evidence_kinds($r['byId'][X]) === ['header']);
expect_states('C-B', $r, [M => 'no_anomaly', S => 'no_anomaly']);

$r = diag(['headers' => ['Link' => ['<' . ALT . '>; rel="canonical"']], 'html' => page(link_canonical(BASE))]);
expect_states('C-C head e Link diversi', $r, [M => 'confirmed_anomaly', X => 'inconclusive', S => 'no_anomaly']);
check('C-C multiple con entrambe le fonti', evidence_kinds($r['byId'][M]) === ['html', 'header']);

$r = diag(['headers' => ['Link' => ['<' . BASE . '>; rel="canonical"']], 'html' => page(link_canonical(BASE))]);
expect_states('C-D head e Link uguali', $r, [M => 'no_anomaly', X => 'no_anomaly']);

$r = diag(['headers' => ['Link' => ['<' . ALT . '>; rel="alternate canonical"; title="x, y", <https://example.test/b/>; rel=stylesheet']], 'html' => page('')]);
check('C-E Link combinato con virgola tra virgolette e rel multiplo: una sola canonical', $r['snap']->observedValue('canonical')->value() === [ALT]);
check('C-E il report non contiene titoli o parametri del Link', strpos((string) json_encode($r['snap']->toArray()), 'x, y') === false);

$r = diag(['headers' => ['Link' => ['</pagina/>; rel=canonical']], 'html' => '<!doctype html><html><head><meta charset="utf-8"><base href="https://other.test/"></head><body></body></html>']);
check('C-F Link relativo risolto contro URL finale', $r['snap']->observedValue('canonical')->value() === [BASE]);
expect_states('C-F', $r, [X => 'no_anomaly', S => 'no_anomaly']);

$r = diag(['headers' => ['Link' => ['<' . BASE . '>; rel="canonical']], 'html' => page(link_canonical(BASE))]);
check('C-G Link sintatticamente ambiguo: canonical unobservable', family_av($r['snap'], 'canonical') === 'unobservable');
expect_states('C-G', $r, [M => 'inconclusive', X => 'inconclusive', S => 'inconclusive']);

$r = diag(['headers' => ['Link' => ['<' . BASE . '>; rel=canonical; anchor="#x"']], 'html' => page('')]);
expect_states('C-H anchor esplicito', $r, [M => 'inconclusive', X => 'inconclusive', S => 'inconclusive']);

$many = [];
for ($i = 0; $i < 65; $i++) {
    $many[] = '<https://example.test/p' . $i . '/>; rel=alternate';
}
$r = diag(['headers' => ['Link' => [implode(', ', $many)]], 'html' => page('')]);
expect_states('C-I oltre 64 voci', $r, [M => 'inconclusive', X => 'inconclusive', S => 'inconclusive']);

$r = diag(['headers' => ['Link' => ['<https://example.test/x/>; rel=alternate; title="' . str_repeat('a', 17000) . '"']], 'html' => page('')]);
expect_states('C-I2 oltre 16 KiB', $r, [M => 'inconclusive', X => 'inconclusive', S => 'inconclusive']);

$r = diag(['headers' => ['Link' => ['<' . BASE . '>; rel=alternate; rel=canonical']], 'html' => page('')]);
expect_states('C-J primo rel vale', $r, [S => 'confirmed_anomaly', X => 'no_anomaly']);

$r = diag(['headers' => ['Link' => ['</style.css>; rel=stylesheet']], 'html' => page('')]);
expect_states('C-K solo relazioni non canonical', $r, [S => 'confirmed_anomaly', X => 'no_anomaly']);

$r = diag(['html' => page('', '<link rel="canonical" href="' . BASE . '">')]);
expect_states('C-L solo fuori head', $r, [M => 'no_anomaly', X => 'inconclusive', S => 'inconclusive']);

$r = diag(['html' => page(link_canonical(BASE), '<link rel="canonical" href="' . ALT . '">')]);
expect_states('C-M head e fuori head discordanti', $r, [M => 'no_anomaly', X => 'inconclusive', S => 'no_anomaly']);

$r = diag(['html' => page(link_canonical(BASE), '<link rel="canonical" href="' . BASE . '">'), 'yoast' => ['canonical' => 'https://example.test/zeta/']]);
expect_states('C-N stesso target in entrambe le posizioni', $r, [M => 'no_anomaly', X => 'confirmed_anomaly']);

// ---------------------------------------------------------------------------
// 4. Regole canonical
// ---------------------------------------------------------------------------

$r = diag(['html' => page('')]);
expect_states('K-1 assenza dimostrata', $r, [S => 'confirmed_anomaly', X => 'no_anomaly', M => 'no_anomaly']);
check('K-1 evidenza di assenza su snapshot completo', evidence_kinds($r['byId'][S]) === ['html']);

$r = diag(['html' => page('<meta name="robots" content="noindex">'), 'yoast' => ['canonical' => '', 'robots' => ['index' => 'noindex', 'follow' => 'follow']]]);
expect_states('K-2 assenza intenzionale con noindex', $r, [S => 'no_anomaly', X => 'no_anomaly', E => 'no_anomaly', U => 'no_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . link_canonical(BASE))]);
expect_states('K-3 duplicati identici', $r, [M => 'no_anomaly', X => 'no_anomaly']);

$r = diag(['html' => page(link_canonical('https://example.test/Pagina/') . link_canonical(BASE))]);
check('K-4 case del path distinto', state_of($r, M) === 'confirmed_anomaly');

$r = diag(['html' => page(link_canonical('https://example.test/pagina/?a=1') . link_canonical('https://example.test/pagina/?a=2'))]);
check('K-4 query distinta', state_of($r, M) === 'confirmed_anomaly');

$r = diag(['html' => page(link_canonical('https://EXAMPLE.test:443/pagina/') . link_canonical(BASE))]);
check('K-4 host e porta di default equivalenti', state_of($r, M) === 'no_anomaly');

$r = diag(['html' => page(link_canonical('https://example.test/pagina') . link_canonical(BASE))]);
check('K-4 trailing slash distinto', state_of($r, M) === 'confirmed_anomaly');

$r = diag(['url' => 'https://example.test/a/b/', 'html' => page(link_canonical('../pagina/')), 'yoast' => ['canonical' => 'https://example.test/a/pagina/']]);
expect_states('K-5 canonical relativo risolto', $r, [X => 'no_anomaly']);

$r = diag([
    'url' => BASE,
    'steps' => [
        BASE => [step(301, '', ['Location' => ALT])],
        ALT => [step(200, page(link_canonical(ALT)), ['Content-Type' => 'text/html; charset=UTF-8'])],
    ],
]);
check('K-6 redirect non equivalente: osservato su altro URL', $r['snap']->observedUrl() === ALT);
expect_states('K-6 redirect non equivalente', $r, [X => 'inconclusive', S => 'inconclusive', E => 'inconclusive', U => 'inconclusive']);

expect_states('K-7 Yoast indisponibile, due canonical distinti', diag(['html' => page(link_canonical(BASE) . link_canonical(ALT)), 'yoast' => 'none']), [M => 'confirmed_anomaly', X => 'inconclusive', S => 'inconclusive', E => 'inconclusive', U => 'inconclusive']);

$r = diag(['html' => page('<meta name="robots" content="noindex">' . link_canonical(BASE)), 'yoast' => ['canonical' => ALT, 'robots' => ['index', 'follow']]]);
expect_states('K-8 noindex pertinente con mismatch', $r, [X => 'inconclusive', E => 'confirmed_anomaly', U => 'confirmed_anomaly', S => 'no_anomaly']);

$r = diag(['html' => page(link_canonical(BASE)), 'body_limit' => 64]);
expect_states('K-9 risposta parziale', $r, [M => 'inconclusive', X => 'inconclusive', S => 'inconclusive', E => 'inconclusive', U => 'inconclusive']);
check('K-9 capture partial', $r['snap']->status() === 'partial');

$r = diag(['html' => page(link_canonical(BASE)), 'status' => 403]);
expect_states('K-10 capture bloccata', $r, [M => 'not_tested', X => 'not_tested', S => 'not_tested', E => 'not_tested', U => 'not_tested']);

// ---------------------------------------------------------------------------
// 5. Regole robots (Googlebot testuale)
// ---------------------------------------------------------------------------

$r = diag(['html' => page(link_canonical(BASE), link_canonical(BASE) . '<meta name="robots" content="noindex">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow']]]);
expect_states('R-body noindex solo nel body con index atteso', $r, [E => 'confirmed_anomaly', U => 'confirmed_anomaly']);
check('R-body prova outside_head', $r['snap']->observedPositions()['robots'] === ['outside_head']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="noindex">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow']]]);
expect_states('R-prova-04 meta noindex con index atteso', $r, [E => 'confirmed_anomaly', U => 'confirmed_anomaly']);
check('R-prova-04 evidenza meta', evidence_kinds($r['byId'][U]) === ['html']);

$r = diag(['html' => page(link_canonical(BASE)), 'headers' => ['X-Robots-Tag' => ['noindex']], 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow']]]);
expect_states('R-prova-05 header noindex', $r, [E => 'confirmed_anomaly', U => 'confirmed_anomaly']);
check('R-prova-05 evidenza header', evidence_kinds($r['byId'][U]) === ['header']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="noindex,follow"><meta name="bingbot" content="index,follow">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow']]]);
expect_states('R-prova-06 noindex atteso e bot fuori scope', $r, [E => 'confirmed_anomaly', U => 'confirmed_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="noindex,follow">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index' => 'noindex', 'follow' => 'follow']]]);
expect_states('R-06 noindex intenzionale', $r, [E => 'no_anomaly', U => 'no_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="index,follow"><meta name="bingbot" content="noindex">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow']]]);
expect_states('R-2 bot diversi non nello scope', $r, [E => 'no_anomaly', U => 'no_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="googlebot" content="noindex">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow']]]);
expect_states('R-3 googlebot noindex', $r, [E => 'confirmed_anomaly', U => 'confirmed_anomaly']);

$r = diag(['html' => page(link_canonical(BASE)), 'headers' => ['X-Robots-Tag' => ['googlebot: nofollow', 'noindex']], 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow']]]);
expect_states('R-4 header ripetuti con ambito', $r, [E => 'confirmed_anomaly', U => 'confirmed_anomaly']);
check('R-4 due evidenze header', count(evidence_kinds($r['byId'][E])) === 2);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="index,noindex">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow']]]);
expect_states('R-5 index e noindex insieme', $r, [U => 'confirmed_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="nofollow">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow']]]);
expect_states('R-7 nofollow inatteso', $r, [E => 'confirmed_anomaly', U => 'no_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="nosnippet">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow', 'max-snippet:0']]]);
expect_states('R-8 nosnippet equivalente a max-snippet:0', $r, [E => 'no_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="max-snippet:50">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow', 'max-snippet:-1']]]);
expect_states('R-9 snippet non equivalente', $r, [E => 'confirmed_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="all">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow']]]);
expect_states('R-10 all equivale a index,follow', $r, [E => 'no_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="none">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index' => 'noindex', 'follow' => 'nofollow']]]);
expect_states('R-11 none equivale a noindex,nofollow', $r, [E => 'no_anomaly', U => 'no_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="max-snippet:abc">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow']]]);
expect_states('R-12 sintassi non valida', $r, [E => 'inconclusive']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="unavailable_after: 25 Jun 2030 15:00:00 PST">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow']]]);
expect_states('R-13 direttiva contestuale', $r, [E => 'inconclusive']);

$r = diag(['html' => page(link_canonical(BASE)), 'yoast' => ['canonical' => BASE, 'robots' => 'THROW']]);
expect_states('R-14 robots Yoast non leggibile', $r, [E => 'inconclusive', U => 'inconclusive']);

$r = diag(['html' => page(link_canonical(BASE)), 'yoast' => ['canonical' => BASE, 'robots' => ['index' => 'noindex', 'follow' => 'follow']]]);
expect_states('R-15 atteso noindex, osservato assente', $r, [E => 'confirmed_anomaly', U => 'no_anomaly']);
check('R-15 evidenza di assenza', evidence_kinds($r['byId'][E]) === ['html']);

$r = diag(['html' => page(link_canonical(BASE)), 'yoast' => ['canonical' => BASE, 'robots' => []]]);
expect_states('R-16 robots Yoast vuoto valido', $r, [E => 'no_anomaly', U => 'no_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="noindex">'), 'yoast' => ['canonical' => BASE, 'robots' => []]]);
check('R-17 noindex osservato con robots Yoast vuoto', state_of($r, U) === 'confirmed_anomaly');

// ---------------------------------------------------------------------------
// 6. Revisione: parametri Link senza valore, limiti esatti, redirect equivalente, anteprime
// ---------------------------------------------------------------------------

$r = diag(['headers' => ['Link' => ['<' . BASE . '>; rel=canonical; crossorigin, </style.css>; rel=preload; as=font; crossorigin']], 'html' => page('')]);
check('L-1 parametri Link senza valore ammessi (RFC 8288)', $r['snap']->observedValue('canonical')->value() === [BASE]);
expect_states('L-1', $r, [X => 'no_anomaly', S => 'no_anomaly']);

$many = [];
for ($i = 0; $i < 63; $i++) {
    $many[] = '<https://example.test/q' . $i . '/>; rel=alternate';
}
$many[] = '<' . BASE . '>; rel=canonical';
$r = diag(['headers' => ['Link' => [implode(', ', $many)]], 'html' => page('')]);
check('L-2 esattamente 64 voci: ammesse', family_av($r['snap'], 'canonical') === 'present' && $r['snap']->observedValue('canonical')->value() === [BASE]);

$prefix = '<' . BASE . '>; rel=canonical; title="';
$padded = $prefix . str_repeat('a', 16384 - strlen($prefix) - 1) . '"';
$r = diag(['headers' => ['Link' => [$padded]], 'html' => page('')]);
check('L-4 esattamente 16 KiB: ammessi', strlen($padded) === 16384 && $r['snap']->observedValue('canonical')->value() === [BASE]);

$r = diag([
    'url' => BASE,
    'steps' => [
        BASE => [step(301, '', ['Location' => 'https://example.test:443/pagina/'])],
        'https://example.test:443/pagina/' => [step(200, page(link_canonical(ALT)), ['Content-Type' => 'text/html; charset=UTF-8'])],
    ],
]);
check('L-3 redirect su URL equivalente: un hop, nessun inconclusive da redirect', $r['snap']->redirects() === ['https://example.test:443/pagina/']);
expect_states('L-3 redirect equivalente', $r, [X => 'confirmed_anomaly']);

$r = diag(['html' => page(link_canonical(BASE)), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow', 'max-image-preview:large']]]);
expect_states('I-1 anteprima large attesa, assente osservata', $r, [E => 'confirmed_anomaly', U => 'no_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="max-image-preview:large">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow', 'max-image-preview:large']]]);
expect_states('I-2 anteprima large uguale', $r, [E => 'no_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="max-image-preview:none">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow', 'max-image-preview:large']]]);
expect_states('I-3 anteprima none diversa da large', $r, [E => 'confirmed_anomaly']);

$r = diag(['html' => page(link_canonical(BASE) . '<meta name="robots" content="max-video-preview:0">'), 'yoast' => ['canonical' => BASE, 'robots' => ['index', 'follow', 'max-video-preview:-1']]]);
expect_states('I-4 anteprima video diversa', $r, [E => 'confirmed_anomaly']);

// ---------------------------------------------------------------------------
// Pulizia e risultato
// ---------------------------------------------------------------------------

if (is_dir($TEMP_DIR)) {
    @rmdir($TEMP_DIR);
}

if ($failures !== []) {
    fwrite(STDERR, 'FALLITI ' . count($failures) . ' controlli (superati ' . $passed . "):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo 'S2 diagnosis: ' . $passed . ' controlli superati, 0 falliti (fixture ' . $fixturesRead . ', scansioni ' . $scansRead . ", casi diagnosi $calls_total)\n";
exit(0);
