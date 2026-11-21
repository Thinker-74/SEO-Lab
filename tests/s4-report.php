<?php

declare(strict_types=1);

/*
 * Verificatore S4 offline di SEO Lab: Scan/Verify con fixture 10 come oracolo, diff, retention, cooldown, azioni
 * admin (input, nonce, capability, ordine dei controlli), proiezione di export, messaggi e escaping della vista.
 * Uso: php tests/s4-report.php. Nessuna rete e nessun WordPress: funzioni WordPress, trasporto, DNS, orologio,
 * provider e archivio sono doppi controllati. Ogni caso e' isolato: un errore e' una riga FAIL con il nome.
 * Ogni warning, notice o deprecation e' un errore.
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
        'SeoLab\\Rules\\' => 'src/Rules/',
        'SeoLab\\Diagnosis\\' => 'src/Diagnosis/',
        'SeoLab\\Storage\\' => 'src/Storage/',
        'SeoLab\\Admin\\' => 'src/Admin/',
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

use SeoLab\Admin\ExportProjection;
use SeoLab\Admin\ReportActions;
use SeoLab\Admin\ReportView;
use SeoLab\Admin\RuleMessages;
use SeoLab\Capture\AddressResolver;
use SeoLab\Capture\SafeHttpCapturer;
use SeoLab\Contracts\ExpectedProvider;
use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\ReportStorage;
use SeoLab\Contracts\RuleCatalog;
use SeoLab\Contracts\ScanReport;
use SeoLab\Diagnosis\ReportDiff;
use SeoLab\Diagnosis\ScanRefused;
use SeoLab\Diagnosis\ScanService;
use SeoLab\Storage\Cooldown;
use SeoLab\Storage\RetentionPolicy;

// ---------------------------------------------------------------------------
// Stub WordPress (namespace globale). Leggono lo stato di test da $GLOBALS.
// ---------------------------------------------------------------------------

class WP_Error
{
    public function get_error_message(): string
    {
        return 'errore di trasporto di test';
    }
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function _e(string $text, string $domain = 'default'): void
{
    echo $text;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

/**
 * Trasporto di test: consegna il body della coda per URL nel file temporaneo indicato da $args['filename'].
 */
function wp_safe_remote_get($url, $args = [])
{
    $queue = $GLOBALS['net'][$url] ?? [];
    if ($queue === []) {
        $GLOBALS['net_violations'][] = 'richiesta non prevista: ' . $url;
        return new WP_Error();
    }
    $step = array_shift($GLOBALS['net'][$url]);
    $GLOBALS['net_calls'][] = $url;
    file_put_contents($args['filename'], substr((string) $step['body'], 0, (int) $args['limit_response_size']));
    return [
        'response' => ['code' => $step['code']],
        'headers' => ['content-type' => ['text/html; charset=UTF-8']],
        'body' => '',
    ];
}

// ---------------------------------------------------------------------------
// Esecuzione dei casi e doppi
// ---------------------------------------------------------------------------

$GLOBALS['s4_pass'] = 0;
$GLOBALS['s4_fail'] = [];
$GLOBALS['net_calls'] = [];
$GLOBALS['net_violations'] = [];
$GLOBALS['t_now'] = 1000000;

/**
 * Esegue un caso: il callable restituisce true se il caso passa. Un'eccezione o un false sono un FAIL nominato.
 */
function t(string $name, callable $case): void
{
    try {
        $ok = $case();
    } catch (\Throwable $e) {
        $GLOBALS['s4_fail'][] = $name . ' [' . get_class($e) . ': ' . $e->getMessage() . ']';
        return;
    }
    if ($ok === true) {
        $GLOBALS['s4_pass']++;
        return;
    }
    $GLOBALS['s4_fail'][] = $name;
}

final class FakeResolver implements AddressResolver
{
    public function resolve(string $host): array
    {
        return $host === 'example.test' ? ['93.184.216.34'] : [];
    }
}

/** Provider doppio: restituisce una risoluzione per chiamata e conta le chiamate. */
final class QueueProvider implements ExpectedProvider
{
    /** @var int */
    public $calls = 0;

    /** @var array<int, array<string, mixed>> */
    private $queue;

    /** @var array<string, mixed> */
    private $fallback;

    public function __construct(array $queue, array $fallback)
    {
        $this->queue = $queue;
        $this->fallback = $fallback;
    }

    public function forPost(int $postId): ExpectedSnapshot
    {
        $this->calls++;
        $data = $this->queue !== [] ? array_shift($this->queue) : $this->fallback;
        return new ExpectedSnapshot($data);
    }
}

/** Archivio in memoria con la stessa semantica di scadenza e potatura della tabella WordPress. */
final class MemoryStorage implements ReportStorage
{
    /** @var bool */
    public $schemaOk = true;

    /** @var bool */
    public $failSave = false;

    /** @var int */
    public $saves = 0;

    /** @var int */
    public $schemaCalls = 0;

    /** @var array<string, array{report: ScanReport, created: int, id: int, post: int}> */
    private $rows = [];

    /** @var int */
    private $nextId = 1;

    public function ensureSchema(): bool
    {
        $this->schemaCalls++;
        return $this->schemaOk;
    }

    public function save(ScanReport $report): void
    {
        $this->saves++;
        if ($this->failSave) {
            throw new \RuntimeException('scrittura non riuscita');
        }
        if (isset($this->rows[$report->scanId()])) {
            throw new \RuntimeException('UUID gia usato');
        }
        $this->seed($report, (int) $GLOBALS['t_now']);
    }

    public function seed(ScanReport $report, int $created): void
    {
        $this->rows[$report->scanId()] = ['report' => $report, 'created' => $created, 'id' => $this->nextId++, 'post' => $report->postId()];
    }

    public function find(string $scanId): ?ScanReport
    {
        $row = $this->rows[$scanId] ?? null;
        if ($row === null || $this->expired($row['created'])) {
            return null;
        }
        return $row['report'];
    }

    public function forPost(int $postId): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row['post'] === $postId && !$this->expired($row['created'])) {
                $out[] = $row['report'];
            }
        }
        return $out;
    }

    public function prune(int $postId, array $mustKeepScanIds): void
    {
        $rows = [];
        foreach ($this->rows as $scanId => $row) {
            if ($row['post'] === $postId) {
                $rows[] = ['id' => $row['id'], 'scan_id' => $scanId, 'created' => $row['created']];
            }
        }
        foreach (RetentionPolicy::prunable($rows, (int) $GLOBALS['t_now'], $mustKeepScanIds) as $id) {
            foreach ($this->rows as $scanId => $row) {
                if ($row['id'] === $id) {
                    unset($this->rows[$scanId]);
                }
            }
        }
    }

    public function countFor(int $postId): int
    {
        $n = 0;
        foreach ($this->rows as $row) {
            if ($row['post'] === $postId) {
                $n++;
            }
        }
        return $n;
    }

    private function expired(int $created): bool
    {
        return (int) $GLOBALS['t_now'] - $created >= RetentionPolicy::MAX_AGE_SECONDS;
    }
}

/**
 * Cattura reale con SafeHttpCapturer: trasporto e DNS controllati, nessuna rete. Restituisce lo snapshot osservato.
 */
function capture_html(int $postId, string $permalink, string $html, int $code = 200): ObservedSnapshot
{
    $GLOBALS['wp'] = [
        'home' => 'https://example.test/',
        'posts' => [$postId => (object) ['post_status' => 'publish', 'post_type' => 'page', 'post_password' => '']],
        'permalinks' => [$postId => $permalink],
    ];
    $GLOBALS['net'] = [$permalink => [['code' => $code, 'body' => $html]]];
    $GLOBALS['clock'] = 1000.0;
    $dir = sys_get_temp_dir() . '/seolab-s4-' . getmypid();
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $capturer = new SafeHttpCapturer(new FakeResolver(), static function (): float {
        return (float) $GLOBALS['clock'];
    }, $dir);
    return $capturer->captureForPost($postId);
}

/** Fixture 10: due scansioni grezze e le risoluzioni Yoast attese. */
$FX = json_decode((string) file_get_contents(__DIR__ . '/fixtures/10-canonical-corrected-rescan.json'), true);
$FX_URL = 'https://example.test/pagina-10/';
$FX_POST = 110;
$FX_HTML1 = $FX['scans'][0]['input']['html'];
$FX_HTML2 = $FX['scans'][1]['input']['html'];
$FX_EXP1 = $FX['scans'][0]['report']['expected'];
$FX_EXP2 = $FX['scans'][1]['report']['expected'];

$uuidSeq = 0;
$nextUuid = static function () use (&$uuidSeq): string {
    $uuidSeq++;
    return sprintf('00000000-0000-4000-8000-%012d', 0x2000 + $uuidSeq);
};

/** Servizio di scansione con capture reale sulla fixture 10 e contatori. */
function fixture_scan_service(array &$c, QueueProvider $provider, callable $nextUuid, bool $eligible = true): ScanService
{
    global $FX_URL, $FX_POST, $FX_HTML1, $FX_HTML2;
    return new ScanService(
        static function (int $id) use (&$c, $eligible): bool {
            $c['eligible'] = ($c['eligible'] ?? 0) + 1;
            return $eligible && $id === 110;
        },
        static function () use (&$c): bool {
            $c['admit'] = ($c['admit'] ?? 0) + 1;
            return true;
        },
        static function (int $id) use (&$c, $FX_URL, $FX_HTML1, $FX_HTML2): ObservedSnapshot {
            $c['capture'] = ($c['capture'] ?? 0) + 1;
            $html = $c['capture'] === 1 ? $FX_HTML1 : $FX_HTML2;
            return capture_html($id, $FX_URL, $html);
        },
        $provider,
        '2.0.0-fixture',
        $nextUuid
    );
}

/** Esiti per regola in ordine di catalogo, usati dai casi di diff. */
function states_of(array $diffs): array
{
    $out = [];
    foreach ($diffs as $diff) {
        $out[$diff->ruleId()] = $diff->state();
    }
    return $out;
}

function base_report_array(): array
{
    global $FX;
    return $FX['scans'][0]['report'];
}

function report_from(array $data): ScanReport
{
    return new ScanReport($data);
}

/** Copia di un report con modifiche applicate: il callable riceve e restituisce l'array. */
function mutate(array $data, callable $change): ScanReport
{
    return new ScanReport($change($data));
}

function set_outcome(array $data, string $ruleId, array $fields): array
{
    foreach ($data['rule_outcomes'] as $i => $o) {
        if ($o['rule_id'] === $ruleId) {
            $data['rule_outcomes'][$i] = $fields + $o;
        }
    }
    return $data;
}

function diff_state(ScanReport $a, ScanReport $b, string $ruleId): string
{
    return states_of(ReportDiff::compare($a, $b))[$ruleId];
}

// ===========================================================================
// 1. Fixture 10 come oracolo: Scan, Verify, rilettura e diff
// ===========================================================================

$fxProvider = new QueueProvider([$FX_EXP1, $FX_EXP2], $FX_EXP1);
$fxCounters = [];
$fxFirst = null;
$fxSecond = null;
$fxBefore = '';
try {
    $fxScan = fixture_scan_service($fxCounters, $fxProvider, $nextUuid);
    $fxFirst = $fxScan->scan($FX_POST);
    $fxBefore = json_encode($fxFirst->toArray());
    $fxSecond = $fxScan->scan($FX_POST);
} catch (\Throwable $e) {
    $GLOBALS['s4_fail'][] = 'fixture 10: esecuzione Scan [' . get_class($e) . ': ' . $e->getMessage() . ']';
}
$fxRule = static function (ScanReport $r, string $id): ?array {
    foreach ($r->toArray()['rule_outcomes'] as $o) {
        if ($o['rule_id'] === $id) {
            return $o;
        }
    }
    return null;
};

t('fixture 10: due catture e due risoluzioni provider', static function () use ($fxProvider, $fxCounters): bool {
    return $fxCounters['capture'] === 2 && $fxProvider->calls === 2;
});

t('fixture 10: esiti canonical come oracolo', static function () use ($fxFirst, $fxSecond, $FX, $fxRule): bool {
    foreach ([[$fxFirst, 0], [$fxSecond, 1]] as [$report, $idx]) {
        foreach ($FX['scans'][$idx]['report']['rule_outcomes'] as $want) {
            $got = $fxRule($report, $want['rule_id']);
            if ($got === null || $got['rule_version'] !== $want['rule_version'] || $got['state'] !== $want['state']
                || $got['cause_state'] !== $want['cause_state']) {
                return false;
            }
        }
    }
    return true;
});

t('fixture 10: otto esiti presenti in ogni scansione', static function () use ($fxFirst, $fxSecond): bool {
    return count($fxFirst->toArray()['rule_outcomes']) === 8 && count($fxSecond->toArray()['rule_outcomes']) === 8;
});

t('fixture 10: finding, valori, excerpt e posizione head come oracolo', static function () use ($fxFirst, $FX): bool {
    $want = $FX['scans'][0]['report']['findings'][0];
    $got = $fxFirst->toArray()['findings'];
    if (count($got) !== 1) {
        return false;
    }
    $f = $got[0];
    $ev = $f['evidence'][0];
    $wantEv = $want['evidence'][0];
    return $f['rule_id'] === $want['rule_id'] && $f['severity'] === $want['severity']
        && $f['expected'] === $want['expected'] && $f['observed'] === $want['observed']
        && $ev['kind'] === $wantEv['kind'] && $ev['excerpt'] === $wantEv['excerpt']
        && strpos($ev['locator'], 'head') !== false && $f['cause']['state'] === 'unknown'
        && trim((string) $f['next_test']) !== '' && trim((string) $f['verification_method']) !== '';
});

t('fixture 10: seconda scansione senza anomalia canonical', static function () use ($fxSecond, $fxRule): bool {
    $o = $fxRule($fxSecond, 'CANONICAL_EXPECTED_MISMATCH');
    return $o['state'] === 'no_anomaly' && count($fxSecond->findings()) === 0;
});

t('fixture 10: report base identico dopo Verify', static function () use ($fxFirst, $fxBefore): bool {
    return json_encode($fxFirst->toArray()) === $fxBefore;
});

t('fixture 10: UUID distinti e hash del corpo diversi', static function () use ($fxFirst, $fxSecond): bool {
    return $fxFirst->scanId() !== $fxSecond->scanId()
        && $fxFirst->observed()->bodySha256() !== $fxSecond->observed()->bodySha256();
});

t('fixture 10: rilettura da JSON senza mutazioni', static function () use ($fxSecond): bool {
    $json = json_encode($fxSecond->toArray());
    $again = new ScanReport(json_decode((string) $json, true));
    return json_encode($again->toArray()) === $json;
});

t('fixture 10: diff CANONICAL_EXPECTED_MISMATCH resolved', static function () use ($fxFirst, $fxSecond): bool {
    return diff_state($fxFirst, $fxSecond, 'CANONICAL_EXPECTED_MISMATCH') === 'resolved';
});

// ===========================================================================
// 2. Tabella del diff
// ===========================================================================

$base = base_report_array();
$baseReport = report_from($base);
$noAnomaly = set_outcome($base, 'CANONICAL_EXPECTED_MISMATCH', ['state' => 'no_anomaly']);
$noAnomaly['findings'] = [];
$healthyReport = report_from($noAnomaly);

t('diff: stessa anomalia con dettaglio incidentale diverso e persists', static function () use ($base, $baseReport): bool {
    $other = mutate($base, static function (array $d): array {
        $d['findings'][0]['evidence'][0]['excerpt'] = "<link rel='canonical' href='https://example.test/vecchia-10/' data-x='1'>";
        $d['findings'][0]['evidence'][0]['locator'] = "//head/link[@rel='canonical'][1]";
        $d['scan_id'] = '00000000-0000-4000-8000-00000000f001';
        $d['captured_at_utc'] = '2026-11-20T17:05:00Z';
        return $d;
    });
    return diff_state($baseReport, $other, 'CANONICAL_EXPECTED_MISMATCH') === 'persists';
});

t('diff: valore osservato cambiato e changed', static function () use ($base, $baseReport): bool {
    $other = mutate($base, static function (array $d): array {
        $d['observed_values']['canonical']['value'] = ['https://example.test/altra/'];
        $d['findings'][0]['observed']['values'] = ['https://example.test/altra/'];
        $d['scan_id'] = '00000000-0000-4000-8000-00000000f002';
        return $d;
    });
    return diff_state($baseReport, $other, 'CANONICAL_EXPECTED_MISMATCH') === 'changed';
});

t('diff: comparsa di anomalia dopo esito sano e changed', static function () use ($baseReport, $healthyReport): bool {
    return diff_state($healthyReport, $baseReport, 'CANONICAL_EXPECTED_MISMATCH') === 'changed';
});

t('diff: nessuna anomalia stabile e persists', static function () use ($healthyReport): bool {
    $again = mutate($healthyReport->toArray(), static function (array $d): array {
        $d['scan_id'] = '00000000-0000-4000-8000-00000000f003';
        return $d;
    });
    return diff_state($healthyReport, $again, 'CANONICAL_EXPECTED_MISMATCH') === 'persists';
});

t('diff: capture partial rende inconclusive tutte le regole', static function () use ($baseReport, $base): bool {
    $partial = mutate($base, static function (array $d): array {
        $d['capture_status'] = 'partial';
        $d['scan_id'] = '00000000-0000-4000-8000-00000000f004';
        $d['findings'] = [];
        foreach (['canonical', 'robots', 'jsonld'] as $family) {
            $d['observed_values'][$family] = ['availability' => 'unobservable', 'value' => null];
        }
        $d['rule_outcomes'] = array_map(static function (array $o): array {
            $o['state'] = 'inconclusive';
            return $o;
        }, $d['rule_outcomes']);
        return $d;
    });
    foreach (states_of(ReportDiff::compare($baseReport, $partial)) as $state) {
        if ($state !== 'inconclusive') {
            return false;
        }
    }
    return true;
});

t('diff: esito not_tested in una scansione e inconclusive solo per la regola', static function () use ($base, $baseReport): bool {
    $nt = set_outcome($base, 'CANONICAL_EXPECTED_MISMATCH', ['state' => 'not_tested']);
    $nt['findings'] = [];
    $nt['scan_id'] = '00000000-0000-4000-8000-00000000f005';
    $states = states_of(ReportDiff::compare($baseReport, report_from($nt)));
    return $states['CANONICAL_EXPECTED_MISMATCH'] === 'inconclusive' && $states['CANONICAL_MULTIPLE_DISTINCT'] === 'persists';
});

t('diff: versione di regola diversa e inconclusive', static function () use ($base, $baseReport): bool {
    $v = set_outcome($base, 'CANONICAL_EXPECTED_MISMATCH', ['rule_version' => '1.0.1']);
    $v['scan_id'] = '00000000-0000-4000-8000-00000000f006';
    $v['findings'][0]['rule_version'] = '1.0.1';
    return diff_state($baseReport, report_from($v), 'CANONICAL_EXPECTED_MISMATCH') === 'inconclusive';
});

t('diff: URL richiesto diverso e inconclusive per tutte le regole', static function () use ($base, $baseReport): bool {
    $u = mutate($base, static function (array $d): array {
        $d['requested_url'] = 'https://example.test/altro/';
        $d['scan_id'] = '00000000-0000-4000-8000-00000000f007';
        return $d;
    });
    foreach (states_of(ReportDiff::compare($baseReport, $u)) as $state) {
        if ($state !== 'inconclusive') {
            return false;
        }
    }
    return true;
});

t('diff: URL equivalenti per CanonicalTargets::normalize sono confrontabili', static function () use ($base, $baseReport): bool {
    $u = mutate($base, static function (array $d): array {
        $d['requested_url'] = 'https://example.test:443/pagina-10/';
        $d['scan_id'] = '00000000-0000-4000-8000-00000000f008';
        return $d;
    });
    return diff_state($baseReport, $u, 'CANONICAL_EXPECTED_MISMATCH') === 'persists';
});

t('diff: post diverso e inconclusive per tutte le regole', static function () use ($base, $baseReport): bool {
    $p = mutate($base, static function (array $d): array {
        $d['post_id'] = 111;
        $d['scan_id'] = '00000000-0000-4000-8000-00000000f009';
        return $d;
    });
    foreach (states_of(ReportDiff::compare($baseReport, $p)) as $state) {
        if ($state !== 'inconclusive') {
            return false;
        }
    }
    return true;
});

t('diff: esito mancante e inconclusive per la regola, ordine di catalogo', static function () use ($base, $baseReport): bool {
    $m = $base;
    $m['rule_outcomes'] = array_values(array_filter($m['rule_outcomes'], static function (array $o): bool {
        return $o['rule_id'] !== 'CANONICAL_EXPECTED_MISMATCH';
    }));
    $m['findings'] = [];
    $m['scan_id'] = '00000000-0000-4000-8000-00000000f00a';
    $diffs = ReportDiff::compare($baseReport, report_from($m));
    $order = [];
    foreach (RuleCatalog::families() as $family) {
        foreach (RuleCatalog::rulesOf($family) as $id) {
            $order[] = $id;
        }
    }
    $got = array_map(static function ($d): string {
        return $d->ruleId();
    }, $diffs);
    return $got === $order && states_of($diffs)['CANONICAL_EXPECTED_MISMATCH'] === 'inconclusive';
});

t('diff: esito mancante solo nel report base e inconclusive per la regola', static function () use ($base, $baseReport): bool {
    $m = $base;
    $m['rule_outcomes'] = array_values(array_filter($m['rule_outcomes'], static function (array $o): bool {
        return $o['rule_id'] !== 'CANONICAL_EXPECTED_MISMATCH';
    }));
    $m['findings'] = [];
    $m['scan_id'] = '00000000-0000-4000-8000-00000000f00b';
    $states = states_of(ReportDiff::compare(report_from($m), $baseReport));
    return $states['CANONICAL_EXPECTED_MISMATCH'] === 'inconclusive' && $states['CANONICAL_MULTIPLE_DISTINCT'] === 'persists';
});

// Capture blocked/failed: nessun body, nessun finding, esito sano per la regola, cosi' che solo lo stato di cattura
// separi il confronto dall'esito 'resolved' (un controllo di stato rimosso deve essere visibile).
$nonOk = static function (array $base, string $status, string $scanId): ScanReport {
    $d = $base;
    $d['capture_status'] = $status;
    $d['scan_id'] = $scanId;
    $d['findings'] = [];
    $d['http']['body_sha256'] = null;
    $d['jsonld_analysis'] = null;
    foreach (['canonical', 'robots', 'jsonld'] as $family) {
        $d['observed_values'][$family] = ['availability' => 'unobservable', 'value' => null];
    }
    return report_from(set_outcome($d, 'CANONICAL_EXPECTED_MISMATCH', ['state' => 'no_anomaly']));
};

t('diff: stato di cattura blocked su una delle due e inconclusive', static function () use ($baseReport, $nonOk): bool {
    $blk = $nonOk(base_report_array(), 'blocked', '00000000-0000-4000-8000-00000000f00c');
    return diff_state($baseReport, $blk, 'CANONICAL_EXPECTED_MISMATCH') === 'inconclusive'
        && diff_state($blk, $baseReport, 'CANONICAL_EXPECTED_MISMATCH') === 'inconclusive';
});

t('diff: stato di cattura failed su una delle due e inconclusive', static function () use ($baseReport, $nonOk): bool {
    $failed = $nonOk(base_report_array(), 'failed', '00000000-0000-4000-8000-00000000f00d');
    return diff_state($baseReport, $failed, 'CANONICAL_EXPECTED_MISMATCH') === 'inconclusive'
        && diff_state($failed, $baseReport, 'CANONICAL_EXPECTED_MISMATCH') === 'inconclusive';
});

t('diff: URL finale diverso con URL richiesto uguale e inconclusive', static function () use ($base, $baseReport): bool {
    $f = mutate($base, static function (array $d): array {
        $d['observed_url'] = 'https://example.test/finale-diversa/';
        $d['scan_id'] = '00000000-0000-4000-8000-00000000f00e';
        return $d;
    });
    return diff_state($baseReport, $f, 'CANONICAL_EXPECTED_MISMATCH') === 'inconclusive';
});

t('diff: locator dentro observed ignorato, stessa anomalia e persists', static function () use ($base): bool {
    $jsonSyntax = static function (string $locator, string $scanId) use ($base): ScanReport {
        return mutate($base, static function (array $d) use ($locator, $scanId): array {
            $d['findings'][0]['observed']['errors'] = [['code' => 'JSON_SYNTAX', 'locator' => $locator]];
            $d['scan_id'] = $scanId;
            return $d;
        });
    };
    $one = $jsonSyntax('script[1]', '00000000-0000-4000-8000-00000000f00f');
    $two = $jsonSyntax('script[2]', '00000000-0000-4000-8000-00000000f010');
    return diff_state($one, $two, 'CANONICAL_EXPECTED_MISMATCH') === 'persists';
});

t('diff: liste usate come insiemi in ordine diverso e persists', static function () use ($base, $baseReport): bool {
    $ab = mutate($base, static function (array $d): array {
        $d['findings'][0]['observed']['values'] = ['https://example.test/a/', 'https://example.test/b/'];
        $d['scan_id'] = '00000000-0000-4000-8000-00000000f011';
        return $d;
    });
    $ba = mutate($base, static function (array $d): array {
        $d['findings'][0]['observed']['values'] = ['https://example.test/b/', 'https://example.test/a/'];
        $d['scan_id'] = '00000000-0000-4000-8000-00000000f012';
        return $d;
    });
    return diff_state($ab, $ba, 'CANONICAL_EXPECTED_MISMATCH') === 'persists';
});

t('diff: chiavi permutate in observed e persists', static function () use ($base): bool {
    $xy = mutate($base, static function (array $d): array {
        $d['findings'][0]['observed']['x'] = 1;
        $d['findings'][0]['observed']['y'] = 2;
        $d['scan_id'] = '00000000-0000-4000-8000-00000000f013';
        return $d;
    });
    $yx = mutate($base, static function (array $d): array {
        $d['findings'][0]['observed'] = ['y' => 2, 'x' => 1] + $d['findings'][0]['observed'];
        $d['scan_id'] = '00000000-0000-4000-8000-00000000f014';
        return $d;
    });
    return diff_state($xy, $yx, 'CANONICAL_EXPECTED_MISMATCH') === 'persists';
});

// ===========================================================================
// 3. Retention e cooldown
// ===========================================================================

$DAY = 86400;
$NOW = 2000000000;

t('retention: eta di 30 giorni scaduta, 30 giorni meno un secondo tenuto', static function () use ($DAY, $NOW): bool {
    $rows = [
        ['id' => 1, 'scan_id' => 'a', 'created' => $NOW - 30 * $DAY],
        ['id' => 2, 'scan_id' => 'b', 'created' => $NOW - 30 * $DAY + 1],
    ];
    return RetentionPolicy::prunable($rows, $NOW, []) === [1];
});

t('retention: cap di 10 con pari timestamp, si tengono gli id piu alti', static function () use ($NOW): bool {
    $rows = [];
    for ($i = 1; $i <= 12; $i++) {
        $rows[] = ['id' => $i, 'scan_id' => 's' . $i, 'created' => $NOW];
    }
    return RetentionPolicy::prunable($rows, $NOW, []) === [1, 2];
});

t('retention: Verify sul decimo preserva base e nuovo, totale 10', static function () use ($DAY, $NOW): bool {
    $rows = [];
    for ($i = 1; $i <= 10; $i++) {
        $rows[] = ['id' => $i, 'scan_id' => 's' . $i, 'created' => $NOW - (10 - $i) * 60];
    }
    $rows[] = ['id' => 11, 'scan_id' => 'nuovo', 'created' => $NOW];
    // Base = s1, il piu vecchio della serie: il decimo report in ordine di recenza.
    $deleted = RetentionPolicy::prunable($rows, $NOW, ['nuovo', 's1']);
    $kept = count($rows) - count($deleted);
    return $deleted === [2] && $kept === 10;
});

t('retention: scansione normale conserva i dieci piu recenti', static function () use ($NOW): bool {
    $rows = [];
    for ($i = 1; $i <= 11; $i++) {
        $rows[] = ['id' => $i, 'scan_id' => 's' . $i, 'created' => $NOW + $i];
    }
    return RetentionPolicy::prunable($rows, $NOW + 20, ['s11']) === [1];
});

t('retention: un report scaduto non e protetto dalla lista da preservare', static function () use ($DAY, $NOW): bool {
    $rows = [
        ['id' => 1, 'scan_id' => 'base', 'created' => $NOW - 31 * $DAY],
        ['id' => 2, 'scan_id' => 'nuovo', 'created' => $NOW],
    ];
    return RetentionPolicy::prunable($rows, $NOW, ['base', 'nuovo']) === [1];
});

t('cooldown: finestra di 10 s sequenziale, i rifiuti non scrivono', static function (): bool {
    $store = null;
    $writes = 0;
    $cool = new Cooldown(static function () use (&$store) {
        return $store;
    }, static function (int $now) use (&$store, &$writes): void {
        $store = $now;
        $writes++;
    }, 10);
    $a = $cool->admit(100);
    $b = $cool->admit(105);
    $c = $cool->admit(109);
    $d = $cool->admit(110);
    return $a === true && $b === false && $c === false && $d === true && $writes === 2;
});

// ===========================================================================
// 4. Scan: eleggibilita e rate limit prima della cattura
// ===========================================================================

t('scan: contenuto non idoneo non chiama provider, cattura ne cooldown', static function () use ($nextUuid): bool {
    $c = [];
    $provider = new QueueProvider([], []);
    $scan = fixture_scan_service($c, $provider, $nextUuid, false);
    try {
        $scan->scan(110);
        return false;
    } catch (ScanRefused $e) {
        return $e->reason() === 'not_eligible' && $provider->calls === 0 && !isset($c['capture']) && !isset($c['admit']);
    }
});

t('scan: rate limit rifiuta prima della cattura e del provider', static function () use ($nextUuid, $FX_EXP1): bool {
    $c = [];
    $provider = new QueueProvider([], $FX_EXP1);
    $scan = new ScanService(
        static function (): bool {
            return true;
        },
        static function () use (&$c): bool {
            $c['admit'] = ($c['admit'] ?? 0) + 1;
            return false;
        },
        static function () use (&$c): ObservedSnapshot {
            $c['capture'] = ($c['capture'] ?? 0) + 1;
            throw new \LogicException('cattura non attesa');
        },
        $provider,
        '2.0.0-fixture',
        $nextUuid
    );
    try {
        $scan->scan(110);
        return false;
    } catch (ScanRefused $e) {
        return $e->reason() === 'rate_limited' && $provider->calls === 0 && !isset($c['capture']);
    }
});

// ===========================================================================
// 5. Azioni admin: metodo, capability, nonce, input, ordine
// ===========================================================================

const NOW_SITE = '2';
const PAGE_URL = 'https://example.test/wp-admin/admin.php?page=seo-lab';

/**
 * Scenario delle azioni: archivio in memoria, scansione reale sulla fixture 10, cooldown reale su $GLOBALS['t_now'].
 */
function action_scenario(array $cfg = []): array
{
    global $FX_EXP1, $FX_URL, $FX_HTML1, $FX_HTML2, $nextUuid;
    $c = [];
    $cool = [];
    $cooldown = new Cooldown(static function () use (&$cool) {
        return $cool['last'] ?? null;
    }, static function (int $now) use (&$cool): void {
        $cool['last'] = $now;
    }, 10);
    $provider = new QueueProvider([], $FX_EXP1);
    $scan = new ScanService(
        static function (int $id) use (&$c): bool {
            $c['eligible'] = ($c['eligible'] ?? 0) + 1;
            return $id === 110;
        },
        static function () use (&$c, $cooldown): bool {
            $c['admit'] = ($c['admit'] ?? 0) + 1;
            return $cooldown->admit((int) $GLOBALS['t_now']);
        },
        static function (int $id) use (&$c, $FX_URL, $FX_HTML1, $FX_HTML2): ObservedSnapshot {
            $c['capture'] = ($c['capture'] ?? 0) + 1;
            return capture_html($id, $FX_URL, $c['capture'] === 1 ? $FX_HTML1 : $FX_HTML2);
        },
        $provider,
        '2.0.0-fixture',
        $nextUuid
    );
    $storage = $cfg['storage'] ?? new MemoryStorage((int) $GLOBALS['t_now']);
    $canFlag = $cfg['can'] ?? true;
    $actions = new ReportActions(
        NOW_SITE,
        static function () use (&$c, $canFlag): bool {
            $c['can'] = ($c['can'] ?? 0) + 1;
            return $canFlag;
        },
        static function (string $action, string $nonce) use (&$c): bool {
            $c['nonce'] = ($c['nonce'] ?? 0) + 1;
            return $nonce === 'ok|' . $action;
        },
        $storage,
        $scan,
        PAGE_URL
    );
    return ['actions' => $actions, 'counters' => &$c, 'storage' => $storage, 'provider' => $provider, 'cool' => &$cool];
}

/** Nessun effetto: nessuna cattura, nessun provider, nessuna scrittura, nessun cooldown. */
function no_effects(array $s): bool
{
    $c = $s['counters'];
    return ($c['capture'] ?? 0) === 0 && $s['provider']->calls === 0 && $s['storage']->saves === 0
        && ($c['admit'] ?? 0) === 0 && ($c['eligible'] ?? 0) === 0 && $s['storage']->schemaCalls === 0;
}

t('azioni: metodo GET rifiutato senza effetti', static function (): bool {
    $s = action_scenario();
    $r = $s['actions']->scan('GET', ['post_id' => '110', 'seo_lab_nonce' => 'ok|seo_lab_scan_' . NOW_SITE]);
    return ($r['error'] ?? '') === 'method_not_allowed' && no_effects($s);
});

t('azioni: capability assente rifiutata prima di nonce e storage', static function (): bool {
    $s = action_scenario(['can' => false]);
    $r = $s['actions']->scan('POST', ['post_id' => '110', 'seo_lab_nonce' => 'ok|seo_lab_scan_' . NOW_SITE]);
    return ($r['error'] ?? '') === 'forbidden' && no_effects($s) && !isset($s['counters']['nonce']);
});

t('azioni: nonce di altro sito o assente rifiutato senza effetti', static function (): bool {
    $s = action_scenario();
    $r1 = $s['actions']->scan('POST', ['post_id' => '110', 'seo_lab_nonce' => 'ok|seo_lab_scan_9']);
    $r2 = $s['actions']->scan('POST', ['post_id' => '110']);
    return ($r1['error'] ?? '') === 'bad_nonce' && ($r2['error'] ?? '') === 'bad_nonce' && no_effects($s);
});

t('azioni: post_id non valido rifiutato senza effetti (array, misto, overflow, zero, decimale, spazi, vuoto)', static function (): bool {
    $bad = [['a'], '12abc', ' 12', '0', '-1', '1.5', '1e3', '', '9999999999999999999999', '012', null, 110];
    foreach ($bad as $raw) {
        $s = action_scenario();
        $req = ['seo_lab_nonce' => 'ok|seo_lab_scan_' . NOW_SITE];
        if ($raw !== null) {
            $req['post_id'] = $raw;
        }
        $r = $s['actions']->scan('POST', $req);
        if (($r['error'] ?? '') !== 'bad_request' || !no_effects($s)) {
            return false;
        }
    }
    return true;
});

t('azioni: solo URL senza post_id non avvia Scan', static function (): bool {
    $s = action_scenario();
    $r = $s['actions']->scan('POST', ['url' => 'https://example.test/pagina-10/', 'seo_lab_nonce' => 'ok|seo_lab_scan_' . NOW_SITE]);
    return ($r['error'] ?? '') === 'bad_request' && no_effects($s);
});

t('azioni: contenuto non idoneo rifiutato prima del cooldown e della cattura', static function (): bool {
    $s = action_scenario();
    $r = $s['actions']->scan('POST', ['post_id' => '111', 'seo_lab_nonce' => 'ok|seo_lab_scan_' . NOW_SITE]);
    return ($r['error'] ?? '') === 'not_eligible' && ($s['counters']['capture'] ?? 0) === 0 && $s['provider']->calls === 0
        && ($s['counters']['admit'] ?? 0) === 0;
});

t('azioni: scan valido salva, reindirizza solo con UUID validato', static function (): bool {
    $s = action_scenario();
    $r = $s['actions']->scan('POST', ['post_id' => '110', 'seo_lab_nonce' => 'ok|seo_lab_scan_' . NOW_SITE, 'redirect' => 'https://evil.example/']);
    if (($r['ok'] ?? false) !== true || $s['storage']->saves !== 1 || $s['provider']->calls !== 1) {
        return false;
    }
    $expected = PAGE_URL . '&report=' . $r['report'];
    return $r['redirect'] === $expected && preg_match('/^[0-9a-f-]{36}$/', (string) $r['report']) === 1;
});

t('azioni: cooldown rifiuta il secondo scan senza cattura, poi accetta dopo la finestra', static function (): bool {
    $s = action_scenario();
    $req = ['post_id' => '110', 'seo_lab_nonce' => 'ok|seo_lab_scan_' . NOW_SITE];
    $first = $s['actions']->scan('POST', $req);
    $GLOBALS['t_now'] += 5;
    $second = $s['actions']->scan('POST', $req);
    $captures = $s['counters']['capture'];
    $GLOBALS['t_now'] += 6;
    $third = $s['actions']->scan('POST', $req);
    return ($first['ok'] ?? false) === true && ($second['error'] ?? '') === 'rate_limited' && $captures === 1
        && ($third['ok'] ?? false) === true && $s['storage']->saves === 2;
});

t('azioni: schema non disponibile rifiutato prima della cattura', static function (): bool {
    $storage = new MemoryStorage((int) $GLOBALS['t_now']);
    $storage->schemaOk = false;
    $s = action_scenario(['storage' => $storage]);
    $r = $s['actions']->scan('POST', ['post_id' => '110', 'seo_lab_nonce' => 'ok|seo_lab_scan_' . NOW_SITE]);
    return ($r['error'] ?? '') === 'storage_unavailable' && ($s['counters']['capture'] ?? 0) === 0;
});

t('azioni: errore di salvataggio non produce un report archiviato', static function (): bool {
    $storage = new MemoryStorage((int) $GLOBALS['t_now']);
    $storage->failSave = true;
    $s = action_scenario(['storage' => $storage]);
    $r = $s['actions']->scan('POST', ['post_id' => '110', 'seo_lab_nonce' => 'ok|seo_lab_scan_' . NOW_SITE]);
    return ($r['error'] ?? '') === 'storage_error' && !isset($r['redirect']) && $storage->countFor(110) === 0;
});

t('azioni: verify usa il post del report base, ignora post_id e URL del client', static function (): bool {
    $s = action_scenario();
    $first = $s['actions']->scan('POST', ['post_id' => '110', 'seo_lab_nonce' => 'ok|seo_lab_scan_' . NOW_SITE]);
    $GLOBALS['t_now'] += 11;
    $uuid = (string) $first['report'];
    $nonce = 'ok|seo_lab_verify_' . NOW_SITE . '_' . $uuid;
    $r = $s['actions']->verify('POST', ['report' => $uuid, 'post_id' => '999', 'url' => 'https://evil.example/', 'seo_lab_nonce' => $nonce]);
    if (($r['ok'] ?? false) !== true || $s['storage']->saves !== 2) {
        return false;
    }
    $newest = $s['storage']->forPost(110);
    return count($newest) === 2 && $r['redirect'] === PAGE_URL . '&report=' . $r['report'] . '&base=' . $uuid;
});

t('azioni: verify con nonce legato a un altro UUID rifiutato', static function (): bool {
    $s = action_scenario();
    $first = $s['actions']->scan('POST', ['post_id' => '110', 'seo_lab_nonce' => 'ok|seo_lab_scan_' . NOW_SITE]);
    $GLOBALS['t_now'] += 11;
    $r = $s['actions']->verify('POST', ['report' => (string) $first['report'], 'seo_lab_nonce' => 'ok|seo_lab_verify_' . NOW_SITE . '_00000000-0000-4000-8000-0000000000ff']);
    return ($r['error'] ?? '') === 'bad_nonce' && $s['storage']->saves === 1 && ($s['counters']['capture'] ?? 0) === 1;
});

t('azioni: verify con UUID inesistente o di altro sito dà lo stesso esito', static function (): bool {
    $s = action_scenario();
    $ghost = '00000000-0000-4000-8000-0000000000aa';
    $r1 = $s['actions']->verify('POST', ['report' => $ghost, 'seo_lab_nonce' => 'ok|seo_lab_verify_' . NOW_SITE . '_' . $ghost]);
    // Report nel contenitore di un altro sito: il sito corrente non lo vede e risponde come per un UUID inesistente.
    $siteB = new MemoryStorage((int) $GLOBALS['t_now']);
    $reportB = report_from(base_report_array());
    $siteB->seed($reportB, (int) $GLOBALS['t_now']);
    $s2 = action_scenario();
    $r2 = $s2['actions']->verify('POST', ['report' => $reportB->scanId(), 'seo_lab_nonce' => 'ok|seo_lab_verify_' . NOW_SITE . '_' . $reportB->scanId()]);
    return ($r1['error'] ?? '') === 'not_found' && ($r2['error'] ?? '') === 'not_found' && ($s2['counters']['capture'] ?? 0) === 0;
});

t('azioni: export solo in POST con nonce legato all UUID, not_found uniforme', static function (): bool {
    $s = action_scenario();
    $first = $s['actions']->scan('POST', ['post_id' => '110', 'seo_lab_nonce' => 'ok|seo_lab_scan_' . NOW_SITE]);
    $uuid = (string) $first['report'];
    $get = $s['actions']->export('GET', ['report' => $uuid, 'seo_lab_nonce' => 'ok|seo_lab_export_' . NOW_SITE . '_' . $uuid]);
    $noNonce = $s['actions']->export('POST', ['report' => $uuid]);
    $ok = $s['actions']->export('POST', ['report' => $uuid, 'seo_lab_nonce' => 'ok|seo_lab_export_' . NOW_SITE . '_' . $uuid]);
    $ghost = '00000000-0000-4000-8000-0000000000bb';
    $nf = $s['actions']->export('POST', ['report' => $ghost, 'seo_lab_nonce' => 'ok|seo_lab_export_' . NOW_SITE . '_' . $ghost]);
    return ($get['error'] ?? '') === 'method_not_allowed' && ($noNonce['error'] ?? '') === 'bad_nonce'
        && ($ok['ok'] ?? false) === true && ($nf['error'] ?? '') === 'not_found'
        && $ok['filename'] === 'seo-lab-report-' . $uuid . '.json';
});

// ===========================================================================
// 6. Proiezione di export
// ===========================================================================

t('export: allowlist, userinfo rimosso, excerpt limitato, nessun body o header', static function (): bool {
    $data = base_report_array();
    $data['requested_url'] = 'https://utente:segreto@example.test/pagina-10/';
    $data['observed_url'] = 'https://utente:segreto@example.test/pagina-10/';
    $data['findings'][0]['evidence'][0]['excerpt'] = str_repeat('x', 900);
    $report = report_from($data);
    $payload = ExportProjection::fromReport($report);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false || json_decode($json, true) === null) {
        return false;
    }
    $keys = array_keys($payload);
    sort($keys);
    $want = ['capture_status', 'captured_at_utc', 'findings', 'http', 'observed_url', 'post_id', 'report_id',
        'requested_url', 'rule_outcomes', 'schema_version', 'source'];
    sort($want);
    $excerpt = $payload['findings'][0]['evidence'][0]['excerpt'];
    return $keys === $want
        && strpos($json, 'segreto') === false && strpos($json, 'utente:') === false
        && strpos($json, 'body') === false && strpos($json, 'set-cookie') === false
        && strlen((string) $excerpt) <= 300;
});

// ===========================================================================
// 7. Messaggi e escaping della vista
// ===========================================================================

t('messaggi: ogni regola P0 ha titolo e istruzione non vuoti', static function (): bool {
    foreach (RuleCatalog::families() as $family) {
        foreach (RuleCatalog::rulesOf($family) as $id) {
            $m = RuleMessages::forRule($id);
            if (trim((string) ($m['title'] ?? '')) === '' || trim((string) ($m['help'] ?? '')) === '') {
                return false;
            }
        }
    }
    return true;
});

t('vista: markup nell excerpt resta testo escapato', static function (): bool {
    $data = base_report_array();
    $data['findings'][0]['evidence'][0]['excerpt'] = "<script>alert('x')</script>";
    $html = ReportView::render(report_from($data), null);
    return strpos($html, '<script>alert') === false && strpos($html, '&lt;script&gt;') !== false;
});

t('vista: esito non disponibile per base assente nel confronto', static function (): bool {
    $html = ReportView::render(report_from(base_report_array()), 'confronto non disponibile');
    return strpos($html, 'confronto non disponibile') !== false;
});

// ===========================================================================
// 8. Catalogo di traduzione e pulizia
// ===========================================================================

t('lingua: catalogo italiano presente per il dominio seo-lab', static function (): bool {
    return is_file(dirname(__DIR__) . '/languages/seo-lab-it_IT.mo') && is_file(dirname(__DIR__) . '/languages/seo-lab-it_IT.po');
});

$tmp = sys_get_temp_dir() . '/seolab-s4-' . getmypid();
if (is_dir($tmp)) {
    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
}

$pass = $GLOBALS['s4_pass'];
$fails = $GLOBALS['s4_fail'];
echo 'S4 offline: ' . $pass . ' superati, ' . count($fails) . " falliti\n";
foreach ($fails as $line) {
    echo 'FAIL ' . $line . "\n";
}
exit($fails === [] ? 0 : 1);
