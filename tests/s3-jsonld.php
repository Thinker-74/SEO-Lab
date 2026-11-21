<?php

declare(strict_types=1);

/*
 * Verificatore S3 di SEO Lab: estrazione JSON-LD (SeoLab\Capture), contratti osservati (SeoLab\Contracts), regole
 * JSON-LD (SeoLab\Rules) e diagnosi (SeoLab\Diagnosis). Uso: php tests/s3-jsonld.php
 * Uscita 0 solo se tutti i controlli passano. Nessuna rete: il provider dell'atteso e' un doppio. Gli esiti delle
 * fixture 07-09 sono l'oracolo: il verificatore non li ricalcola dal modello ma li confronta con l'output reale.
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

use SeoLab\Capture\HtmlExtractor;
use SeoLab\Contracts\ExpectedProvider;
use SeoLab\Contracts\ExpectedSnapshot;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\ObservedValue;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\ScanReport;
use SeoLab\Diagnosis\JsonLdDiagnoser;

const BASE = 'https://example.test/pagina/';
const FIXTURES = __DIR__ . '/fixtures/';
const SCAN_UUID = '00000000-0000-4000-8000-0000000003a1';
const PARSE = 'JSONLD_PARSE_ERROR';
const CONTRA = 'JSONLD_ENTITY_CONTRADICTION';
const MISSING = 'JSONLD_EXPECTED_NODE_MISSING';

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

/** Provider doppio: restituisce sempre lo snapshot dato, senza alcuna chiamata a Yoast. */
final class FixedProvider implements ExpectedProvider
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

function unavailable(): array
{
    return ['availability' => 'unavailable', 'value' => null, 'source' => null];
}

/**
 * Atteso risolto (yoast:resolved). $ids null = jsonld indisponibile; $canonical null = canonical indisponibile.
 *
 * @param string[]|null $ids
 */
function expected_of(?array $ids, ?string $canonical = null): ExpectedSnapshot
{
    $resolved = [
        'canonical' => $canonical === null ? unavailable() : ['availability' => 'available', 'value' => $canonical, 'source' => 'yoast:resolved'],
        'robots' => unavailable(),
        'jsonld' => $ids === null ? unavailable() : ['availability' => 'available', 'value' => ['expected_node_ids' => $ids], 'source' => 'yoast:resolved'],
    ];
    $configured = ['canonical' => unavailable(), 'robots' => unavailable(), 'jsonld' => unavailable()];
    return new ExpectedSnapshot(['configured' => $configured, 'resolved' => $resolved]);
}

/**
 * Cattura osservata da HTML grezzo tramite l'estrattore S1 + S3. $opts: url, requested, redirects, capture
 * (ok|partial), analysis (false per simulare uno snapshot storico senza analisi JSON-LD).
 */
function observe(string $html, array $opts = []): ObservedSnapshot
{
    $url = $opts['url'] ?? BASE;
    $capture = $opts['capture'] ?? 'ok';
    $extracted = HtmlExtractor::extract($html, null, $url, true, []);
    if (isset($extracted['error'])) {
        throw new \RuntimeException('estrazione fallita: ' . $extracted['error']);
    }
    $values = $extracted['values'];
    if ($capture !== 'ok') {
        // Una capture parziale non puo' dichiarare absent: le famiglie vuote diventano unobservable.
        foreach ($values as $family => $value) {
            if ($value['availability'] === 'absent') {
                $values[$family] = ['availability' => 'unobservable', 'value' => null];
            }
        }
    }
    $data = [
        'capture_status' => $capture,
        'capture_reason' => $capture === 'ok' ? null : 'body_limit',
        'requested_url' => $opts['requested'] ?? $url,
        'observed_url' => $url,
        'captured_at_utc' => '2026-11-21T10:00:00Z',
        'http' => [
            'status' => 200,
            'content_type' => 'text/html; charset=UTF-8',
            'body_sha256' => hash('sha256', $html),
            'redirects' => $opts['redirects'] ?? [],
            'x_robots_tag' => [],
        ],
        'observed_values' => $values,
        'observed_positions' => $extracted['positions'],
    ];
    if (($opts['analysis'] ?? true) && isset($extracted['jsonld_analysis'])) {
        $data['jsonld_analysis'] = $extracted['jsonld_analysis'];
    }
    return new ObservedSnapshot($data);
}

/** @param RuleOutcome[] $outcomes */
function scan_report_of(ObservedSnapshot $observed, array $outcomes, ExpectedSnapshot $expected): ScanReport
{
    $rows = [];
    $findings = [];
    foreach ($outcomes as $outcome) {
        $rows[] = $outcome->toArray();
        if ($outcome->finding() !== null) {
            $findings[] = $outcome->finding()->toArray();
        }
    }
    return new ScanReport(['scan_id' => SCAN_UUID, 'schema_version' => '1', 'post_id' => 1,
        'source' => ['provider' => 'yoast', 'provider_version' => 'test'], 'expected' => $expected->toArray(),
        'rule_outcomes' => $rows, 'findings' => $findings] + $observed->toArray());
}

/** Esiti JSON-LD (tre regole) per una coppia atteso/osservato, indicizzati per rule_id. */
function run_rules(ExpectedSnapshot $expected, ObservedSnapshot $observed): array
{
    $outcomes = (new JsonLdDiagnoser(new FixedProvider($expected)))->diagnose(1, $observed);
    $byId = [];
    foreach ($outcomes as $outcome) {
        $byId[$outcome->ruleId()] = $outcome;
    }
    return $byId;
}

function state(array $byId, string $rule): string
{
    return isset($byId[$rule]) ? $byId[$rule]->state() : 'missing';
}

/** Blocco JSON-LD grezzo come lo emette una pagina. */
function jl(string $json, string $type = 'application/ld+json'): string
{
    return "<script type='" . $type . "'>" . $json . '</script>';
}

function doc(string ...$scripts): string
{
    return '<!doctype html><html lang="it"><head><meta charset="utf-8"><title>S3</title>' . implode('', $scripts)
        . '</head><body><main><h1>S3</h1></main></body></html>';
}

/** Oggetto WebPage valido per la pagina BASE. */
function webpage(string $url = BASE, string $id = BASE . '#webpage'): string
{
    return '{"@context":"https://schema.org","@type":"WebPage","@id":"' . $id . '","url":"' . $url . '"}';
}

/** Lista di nodi di un @graph con $n definizioni distinte. */
function nodes_graph(int $n): string
{
    $items = [];
    for ($i = 1; $i <= $n; $i++) {
        $items[] = '{"@type":"Thing","@id":"https://example.test/n/' . $i . '"}';
    }
    return '{"@context":"https://schema.org","@graph":[' . implode(',', $items) . ']}';
}

/** JSON valido di esattamente $bytes byte (per i confini di budget). */
function json_of_size(int $bytes): string
{
    $head = '{"@type":"Thing","name":"';
    $tail = '"}';
    return $head . str_repeat('a', $bytes - strlen($head) - strlen($tail)) . $tail;
}

function syntax_errors(ObservedSnapshot $observed): array
{
    $analysis = $observed->jsonLdAnalysis();
    return $analysis === null ? [] : array_column($analysis['errors'], 'code');
}

function analysis_of(ObservedSnapshot $observed): array
{
    $analysis = $observed->jsonLdAnalysis();
    if ($analysis === null) {
        throw new \RuntimeException('analisi JSON-LD assente');
    }
    return $analysis;
}

function nodes_of(ObservedSnapshot $observed): array
{
    return $observed->observedValue('jsonld')->value() ?? [];
}

// ---------------------------------------------------------------------------
// 1. Fixture 07-09: esiti, prove e locatori uguali agli oracoli
// ---------------------------------------------------------------------------

$fixturesRead = 0;
foreach (['07-jsonld-id-contradiction.json', '08-jsonld-equivalent-nodes.json', '09-jsonld-shared-reference.json'] as $name) {
    $fixture = json_decode((string) file_get_contents(FIXTURES . $name), true, 512, JSON_THROW_ON_ERROR);
    $scan = $fixture['scans'][0];
    $report = $scan['report'];
    $fid = $fixture['fixture_id'];
    $fixturesRead++;

    $observed = observe($scan['input']['html'], ['url' => $report['observed_url'], 'requested' => $report['requested_url']]);
    $expected = new ExpectedSnapshot(['configured' => $report['expected']['configured'], 'resolved' => $report['expected']['resolved']]);
    $byId = run_rules($expected, $observed);

    check('F' . $fid . ' tre esiti JSON-LD', array_keys($byId) === [PARSE, CONTRA, MISSING]);
    foreach ($report['rule_outcomes'] as $row) {
        if (strpos($row['rule_id'], 'JSONLD_') !== 0) {
            continue;
        }
        check('F' . $fid . ' stato ' . $row['rule_id'], state($byId, $row['rule_id']) === $row['state'], state($byId, $row['rule_id']));
        check('F' . $fid . ' causa ' . $row['rule_id'], $byId[$row['rule_id']]->causeState() === $row['cause_state']);
    }
    $expectedFindings = array_values(array_filter($report['findings'], static function (array $f): bool {
        return strpos($f['rule_id'], 'JSONLD_') === 0;
    }));
    $gotFindings = [];
    foreach ($byId as $outcome) {
        if ($outcome->finding() !== null) {
            $gotFindings[] = $outcome->finding()->toArray();
        }
    }
    check('F' . $fid . ' finding uguali all oracolo', $gotFindings === $expectedFindings, json_encode($gotFindings));
    $report2 = scan_report_of($observed, array_values($byId), $expected);
    check('F' . $fid . ' round trip ScanReport', json_encode((new ScanReport($report2->toArray()))->toArray()) === json_encode($report2->toArray()));
}
check('F fixture 07-09 lette', $fixturesRead === 3, (string) $fixturesRead);

// 07 senza atteso Yoast: la contraddizione osservativa resta confermata, l'ID atteso non e' valutabile.
$f07 = json_decode((string) file_get_contents(FIXTURES . '07-jsonld-id-contradiction.json'), true, 512, JSON_THROW_ON_ERROR)['scans'][0];
$o07 = observe($f07['input']['html'], ['url' => $f07['report']['observed_url']]);
$r07 = run_rules(expected_of(null, null), $o07);
check('F07 senza atteso: contraddizione confermata', state($r07, CONTRA) === 'confirmed_anomaly');
check('F07 senza atteso: finding senza expected', $r07[CONTRA]->finding() !== null && $r07[CONTRA]->finding()->toArray()['expected'] === []);
check('F07 senza atteso: mancanza inconclusiva', state($r07, MISSING) === 'inconclusive');
check('F07 senza atteso: sintassi no_anomaly', state($r07, PARSE) === 'no_anomaly');

// 08/09 con atteso Yoast indipendente: negativi, nessun finding.
$f08 = json_decode((string) file_get_contents(FIXTURES . '08-jsonld-equivalent-nodes.json'), true, 512, JSON_THROW_ON_ERROR)['scans'][0];
$o08 = observe($f08['input']['html'], ['url' => $f08['report']['observed_url']]);
$r08 = run_rules(expected_of(['https://example.test/#website'], BASE), $o08);
check('F08 tre negativi', state($r08, PARSE) === 'no_anomaly' && state($r08, CONTRA) === 'no_anomaly' && state($r08, MISSING) === 'no_anomaly');

// ---------------------------------------------------------------------------
// 2. Sintassi: codici stabili, positivi solo su blocchi completi, il resto e' inconclusivo
// ---------------------------------------------------------------------------

$valid = observe(doc(jl(webpage())), ['url' => BASE]);
check('S1 JSON valido: no_anomaly', state(run_rules(expected_of(null), $valid), PARSE) === 'no_anomaly');
check('S1 JSON valido: sintassi completa', analysis_of($valid)['syntax_complete'] === true);
check('S1 JSON valido: inventario completo', analysis_of($valid)['inventory_complete'] === true);
check('S1 JSON valido: profilo completo', analysis_of($valid)['profile_complete'] === true);

$truncated = observe(doc(jl('{"@context":"https://schema.org","@type":"WebPage"')), ['url' => BASE]);
$rt = run_rules(expected_of(null), $truncated);
check('S2 troncato: confermato', state($rt, PARSE) === 'confirmed_anomaly');
check('S2 troncato: codice JSON_SYNTAX', syntax_errors($truncated) === ['JSON_SYNTAX']);
check('S2 troncato: locator script[1]', $rt[PARSE]->finding() !== null && $rt[PARSE]->finding()->toArray()['evidence'][0]['locator'] === 'script[1]');

$trailing = observe(doc(jl('{"@type":"WebPage",}')), ['url' => BASE]);
check('S3 virgola finale: confermato', state(run_rules(expected_of(null), $trailing), PARSE) === 'confirmed_anomaly');

$surrogate = observe(doc(jl('{"@type":"WebPage","name":"\ud800"}')), ['url' => BASE]);
$rs = run_rules(expected_of(null), $surrogate);
check('S4 surrogato isolato: inconclusivo, non errore', state($rs, PARSE) === 'inconclusive' && syntax_errors($surrogate) === ['JSON_UNINTERPRETABLE']);

$deep32 = observe(doc(jl(str_repeat('[', 32) . str_repeat(']', 32))), ['url' => BASE]);
check('S5 32 contenitori: valido', analysis_of($deep32)['syntax_complete'] === true && state(run_rules(expected_of(null), $deep32), PARSE) === 'no_anomaly');
$deep33 = observe(doc(jl(str_repeat('[', 33) . str_repeat(']', 33))), ['url' => BASE]);
$rd = run_rules(expected_of(null), $deep33);
check('S5 33 contenitori: limite, inconclusivo', state($rd, PARSE) === 'inconclusive' && syntax_errors($deep33) === ['JSON_DEPTH_LIMIT']);

$empty = observe(doc(jl('')), ['url' => BASE]);
check('S6 blocco vuoto: inconclusivo, nessun errore provato', state(run_rules(expected_of(null), $empty), PARSE) === 'inconclusive' && syntax_errors($empty) === ['JSON_EMPTY']);
$blank = observe(doc(jl("  \n ")), ['url' => BASE]);
check('S6 blocco di soli spazi: inconclusivo', state(run_rules(expected_of(null), $blank), PARSE) === 'inconclusive');

$unmapped = observe(doc(jl('{"@type":"Thing"' . "\x00" . '}')), ['url' => BASE]);
check('S6b byte NUL: testo DOM non ritrovato nel body, JSON_UNMAPPED, inconclusivo',
    syntax_errors($unmapped) === ['JSON_UNMAPPED'] && state(run_rules(expected_of(null), $unmapped), PARSE) === 'inconclusive');

$inNoscript = observe(doc('<noscript>' . jl('{bad') . '</noscript>', '<template>' . jl('{bad') . '</template>'), ['url' => BASE]);
check('S7 noscript e template esclusi', analysis_of($inNoscript)['blocks'] === 0 && $inNoscript->observedValue('jsonld')->availability() === 'absent');
check('S7 nessuno script: nessuna anomalia', state(run_rules(expected_of(null), $inNoscript), PARSE) === 'no_anomaly');

$upper = observe('<!doctype html><html lang="it"><head><script type="application/ld+json">' . webpage() . '</SCRIPT></head><body></body></html>', ['url' => BASE]);
check('S8 end tag maiuscolo: blocco mappato e valido', analysis_of($upper)['blocks'] === 1 && analysis_of($upper)['syntax_complete'] === true);
$mime = observe(doc(jl(webpage(), 'Application/LD+JSON; charset=utf-8')), ['url' => BASE]);
check('S8 MIME parametrico e maiuscolo riconosciuto', analysis_of($mime)['blocks'] === 1 && state(run_rules(expected_of(null), $mime), PARSE) === 'no_anomaly');

$escapedKey = observe(doc(jl('{"@type":"WebPage","\\u0040id":"' . BASE . '#webpage"}')), ['url' => BASE]);
check('S9 chiave con escape riconosciuta come @id', nodes_of($escapedKey)[0]['id'] === BASE . '#webpage');

// ---------------------------------------------------------------------------
// 3. Limiti: confini inclusivi N e N+1, copertura incompleta, errore gia provato
// ---------------------------------------------------------------------------

$scripts32 = array_fill(0, 32, jl('{"@type":"Thing"}'));
$l32 = observe(doc(...$scripts32), ['url' => BASE]);
check('L1 32 script: copertura completa', analysis_of($l32)['syntax_complete'] === true && analysis_of($l32)['blocks'] === 32);
$scripts33 = array_fill(0, 33, jl('{"@type":"Thing"}'));
$l33 = observe(doc(...$scripts33), ['url' => BASE]);
$rl33 = run_rules(expected_of(null), $l33);
check('L2 33 script: copertura incompleta', analysis_of($l33)['syntax_complete'] === false && syntax_errors($l33) === ['BUDGET_SCRIPTS']);
check('L2 33 script: sintassi inconclusiva', state($rl33, PARSE) === 'inconclusive');
$scripts33err = $scripts33;
$scripts33err[1] = jl('{"@type":');
$l33err = observe(doc(...$scripts33err), ['url' => BASE]);
check('L3 errore provato dentro il budget resta confermato', state(run_rules(expected_of(null), $l33err), PARSE) === 'confirmed_anomaly');

$exact = observe(doc(jl(json_of_size(65536))), ['url' => BASE]);
check('L4 64 KiB esatti: analizzato', analysis_of($exact)['syntax_complete'] === true);
$over = observe(doc(jl(json_of_size(65537))), ['url' => BASE]);
check('L4 64 KiB + 1: copertura incompleta', analysis_of($over)['syntax_complete'] === false && syntax_errors($over) === ['BUDGET_SCRIPT_BYTES']);

$four = array_fill(0, 4, jl(json_of_size(65536)));
$total = observe(doc(...$four), ['url' => BASE]);
check('L5 256 KiB aggregati esatti: completi', analysis_of($total)['syntax_complete'] === true);
$total5 = observe(doc(...array_merge($four, [jl('{"@type":"Thing"}')])), ['url' => BASE]);
check('L5 256 KiB + 1: copertura incompleta', analysis_of($total5)['syntax_complete'] === false && syntax_errors($total5) === ['BUDGET_TOTAL_BYTES']);

$nodes256 = observe(doc(jl(nodes_graph(256))), ['url' => BASE]);
check('L6 256 nodi: inventario completo', analysis_of($nodes256)['inventory_complete'] === true && count(nodes_of($nodes256)) === 256);
$nodes257 = observe(doc(jl(nodes_graph(257))), ['url' => BASE]);
check('L6 257 nodi: inventario incompleto', analysis_of($nodes257)['inventory_complete'] === false && syntax_errors($nodes257) === ['BUDGET_NODES']);
check('L6 257 nodi: sintassi resta completa', analysis_of($nodes257)['syntax_complete'] === true);

// ---------------------------------------------------------------------------
// 4. Profilo e identita': contesto, grafi nominati, riferimenti, locator, duplicati
// ---------------------------------------------------------------------------

$httpCtx = observe(doc(jl('{"@context":"http://schema.org/","@type":"WebPage","@id":"' . BASE . '#w"}')), ['url' => BASE]);
check('P1 http://schema.org/ nel profilo', nodes_of($httpCtx)[0]['profile'] === true);
$httpsNoSlash = observe(doc(jl('{"@context":"https://schema.org","@type":"WebPage","@id":"' . BASE . '#w"}')), ['url' => BASE]);
check('P1 https://schema.org nel profilo', nodes_of($httpsNoSlash)[0]['profile'] === true);

$customCtx = observe(doc(jl('{"@context":"https://example.org/ctx","@type":"WebPage","@id":"' . BASE . '#w"}')), ['url' => BASE]);
check('P2 contesto custom fuori profilo', nodes_of($customCtx)[0]['profile'] === false && analysis_of($customCtx)['profile_complete'] === false);
$noCtx = observe(doc(jl('{"@type":"WebPage","@id":"' . BASE . '#w"}')), ['url' => BASE]);
check('P2 nessun contesto fuori profilo', nodes_of($noCtx)[0]['profile'] === false);

$named = observe(doc(jl('{"@context":"https://schema.org","@id":"https://example.test/g#1","@graph":[{"@type":"WebPage","@id":"' . BASE . '#w"}]}')), ['url' => BASE]);
check('P3 grafo nominato fuori profilo', nodes_of($named)[0]['profile'] === false);

$refOnly = observe(doc(jl('{"@context":"https://schema.org","@type":"WebPage","@id":"' . BASE . '#w","publisher":{"@id":"https://example.test/#org"}}')), ['url' => BASE]);
check('P4 riferimento non e\' definizione', count(nodes_of($refOnly)) === 1 && analysis_of($refOnly)['reference_ids'] === ['https://example.test/#org']);

$nested = observe(doc(jl('{"@context":"https://schema.org","@graph":[{"@type":"WebPage","@id":"' . BASE . '#w","author":{"@id":"https://example.test/#p","name":"P"}}]}')), ['url' => BASE]);
$nestedNodes = nodes_of($nested);
check('P5 nodo annidato definito e locator', count($nestedNodes) === 2 && $nestedNodes[1]['locator'] === 'script[1]/@graph[1]/author');

$arrayRoot = observe(doc(jl('[{"@context":"https://schema.org","@type":"Thing","@id":"https://example.test/a"},{"@context":"https://schema.org","@type":"Thing","@id":"https://example.test/b"}]')), ['url' => BASE]);
check('P6 locator radice array base 1', nodes_of($arrayRoot)[1]['locator'] === 'script[1][2]');
$listProp = observe(doc(jl('{"@context":"https://schema.org","@type":"WebPage","@id":"' . BASE . '#w","mainEntity":[{"@type":"Thing","@id":"https://example.test/x"},{"@type":"Thing","@id":"https://example.test/y"}]}')), ['url' => BASE]);
check('P6 locator proprieta lista', nodes_of($listProp)[2]['locator'] === 'script[1]/mainEntity[2]');

$relId = observe(doc(jl('{"@context":"https://schema.org","@type":"WebPage","@id":"#w"}')), ['url' => BASE]);
check('P7 @id relativo fuori profilo', nodes_of($relId)[0]['profile'] === false);
$badId = observe(doc(jl('{"@context":"https://schema.org","@type":"WebPage","@id":5}')), ['url' => BASE]);
check('P7 @id non stringa: inventario incompleto', analysis_of($badId)['inventory_complete'] === false && syntax_errors($badId) === ['NODE_ID_INVALID']);

$dupLast = observe(doc(jl('{"@context":"https://schema.org","@type":"WebPage","url":"https://a.example.test/","url":"https://b.example.test/"}')), ['url' => BASE]);
check('P8 chiavi duplicate: ultimo valore', nodes_of($dupLast)[0]['url'] === 'https://b.example.test/' && nodes_of($dupLast)[0]['url_state'] === 'scalar');
check('P8 chiavi duplicate: sintassi valida', analysis_of($dupLast)['syntax_complete'] === true);

$types = observe(doc(jl('{"@context":"https://schema.org","@type":["Thing","WebPage"],"@id":"' . BASE . '#w"}')), ['url' => BASE]);
check('P11 @type lista con WebPage', nodes_of($types)[0]['types'] === ['Thing', 'WebPage']);

// ---------------------------------------------------------------------------
// 5. Conflitto WebPage: positivo solo con due URL primari scalari realmente diversi, stesso ID e contesto
// ---------------------------------------------------------------------------

function conflict(array $scripts, string $url = BASE): array
{
    $observed = observe(doc(...$scripts), ['url' => $url]);
    return run_rules(expected_of(null), $observed);
}

$pairSelf = conflict([jl(webpage()), jl(webpage('https://example.test/altra/'))]);
check('C1 due URL diverse, una uguale alla pagina: confermato', state($pairSelf, CONTRA) === 'confirmed_anomaly');
check('C1 evidenze nell\'ordine del documento', $pairSelf[CONTRA]->finding() !== null
    && array_column($pairSelf[CONTRA]->finding()->toArray()['evidence'], 'excerpt') === [BASE, 'https://example.test/altra/']);

$same = conflict([jl(webpage()), jl(webpage())]);
check('C2 ripetizione equivalente: nessuna anomalia', state($same, CONTRA) === 'no_anomaly');

$arrayUrl = conflict([jl(webpage()), jl('{"@context":"https://schema.org","@type":"WebPage","@id":"' . BASE . '#webpage","url":["https://example.test/altra/"]}')]);
check('C3 url array nel gruppo: inconclusivo', state($arrayUrl, CONTRA) === 'inconclusive');

$otherOrigin = conflict([jl(webpage()), jl(webpage('https://altro.test/'))]);
check('C4 seconda URL su altra origine: nessuna anomalia', state($otherOrigin, CONTRA) === 'no_anomaly');

$noSelf = conflict([jl(webpage('https://example.test/uno/')), jl(webpage('https://example.test/due/'))]);
check('C5 nessuna URL uguale alla pagina: nessuna anomalia', state($noSelf, CONTRA) === 'no_anomaly');

$otherType = conflict([jl(webpage()), jl('{"@context":"https://schema.org","@type":"Organization","@id":"' . BASE . '#webpage","url":"https://example.test/altra/"}')]);
check('C6 tipo diverso: nessuna anomalia', state($otherType, CONTRA) === 'no_anomaly');

$unlinked = conflict([jl(webpage(BASE, 'https://otro.test/#webpage')), jl(webpage('https://example.test/altra/', 'https://otro.test/#webpage'))]);
check('C7 ID non legato alla pagina: nessuna anomalia', state($unlinked, CONTRA) === 'no_anomaly');

$relUrl = conflict([jl(webpage()), jl('{"@context":"https://schema.org","@type":"WebPage","@id":"' . BASE . '#webpage","url":"/altra/"}')]);
check('C8 URL relativa: nessuna anomalia', state($relUrl, CONTRA) === 'no_anomaly');

$blankNode = conflict([jl('{"@context":"https://schema.org","@type":"WebPage","url":"' . BASE . '"}'), jl('{"@context":"https://schema.org","@type":"WebPage","url":"https://example.test/altra/"}')]);
check('C8 blank node: nessuna anomalia', state($blankNode, CONTRA) === 'no_anomaly');

$arrayType = conflict([jl('{"@context":"https://schema.org","@type":["Thing","WebPage"],"@id":"' . BASE . '#webpage","url":"' . BASE . '"}'), jl('{"@context":"https://schema.org","@type":["WebPage"],"@id":"' . BASE . '#webpage","url":"https://example.test/altra/"}')]);
check('C9 @type array con WebPage: confermato', state($arrayType, CONTRA) === 'confirmed_anomaly');

$fragmentPage = conflict([jl(webpage(BASE, BASE . '#webpage')), jl(webpage('https://example.test/altra/', BASE . '#webpage'))]);
check('C10 ID con frammento legato alla pagina', state($fragmentPage, CONTRA) === 'confirmed_anomaly');

$ctxMismatch = conflict([jl(webpage()), jl('{"@context":"https://example.org/ctx","@type":"WebPage","@id":"' . BASE . '#webpage","url":"https://example.test/altra/"}')]);
check('C11 contesto non equivalente sul gruppo: inconclusivo', state($ctxMismatch, CONTRA) === 'inconclusive');

$partial = observe(doc(jl(webpage()), jl(webpage('https://example.test/altra/'))), ['url' => BASE, 'capture' => 'partial']);
check('C12 capture parziale: inconclusivo', state(run_rules(expected_of(null), $partial), CONTRA) === 'inconclusive');

$f07Expected = conflict([jl(webpage()), jl(webpage('https://example.test/altra/'))]);
check('C13 finding senza atteso: expected vuoto', $f07Expected[CONTRA]->finding() !== null && $f07Expected[CONTRA]->finding()->toArray()['expected'] === []);

// ---------------------------------------------------------------------------
// 6. Atteso mancante: definizione presente, sola referenza, incompletezza, storico
// ---------------------------------------------------------------------------

$website = 'https://example.test/#website';
$defined = observe(doc(jl('{"@context":"https://schema.org","@type":"WebSite","@id":"' . $website . '","url":"https://example.test/"}')), ['url' => BASE]);
check('M1 definizione presente: nessuna anomalia', state(run_rules(expected_of([$website]), $defined), MISSING) === 'no_anomaly');

$absent = observe(doc(jl(webpage())), ['url' => BASE]);
$rAbsent = run_rules(expected_of([$website]), $absent);
check('M2 ID atteso assente con inventario completo: confermato', state($rAbsent, MISSING) === 'confirmed_anomaly');
check('M2 evidenza di copertura', $rAbsent[MISSING]->finding() !== null && $rAbsent[MISSING]->finding()->toArray()['evidence'][0]['kind'] === 'jsonld');

$onlyRef = observe(doc(jl('{"@context":"https://schema.org","@type":"WebPage","@id":"' . BASE . '#webpage","about":{"@id":"' . $website . '"}}')), ['url' => BASE]);
check('M3 solo referenza: inconclusivo', state(run_rules(expected_of([$website]), $onlyRef), MISSING) === 'inconclusive');

$brokenPlusMissing = observe(doc(jl(webpage()), jl('{"@type":')), ['url' => BASE]);
check('M4 sintassi incompleta: inconclusivo', state(run_rules(expected_of([$website]), $brokenPlusMissing), MISSING) === 'inconclusive');

check('M5 atteso indisponibile: inconclusivo', state(run_rules(expected_of(null), $absent), MISSING) === 'inconclusive');

$emptyObj = observe(doc(jl('{}')), ['url' => BASE]);
check('M6 {} integro: senza definizioni, mancanza confermata', state(run_rules(expected_of([$website]), $emptyObj), MISSING) === 'confirmed_anomaly');
check('M6 {} integro: presente con lista vuota', $emptyObj->observedValue('jsonld')->availability() === 'present' && nodes_of($emptyObj) === []);
$emptyArr = observe(doc(jl('[]')), ['url' => BASE]);
check('M6 [] integro: mancanza confermata', state(run_rules(expected_of([$website]), $emptyArr), MISSING) === 'confirmed_anomaly');

$noScript = observe(doc(), ['url' => BASE]);
check('M7 nessuno script: absent, mancanza confermata', $noScript->observedValue('jsonld')->availability() === 'absent' && state(run_rules(expected_of([$website]), $noScript), MISSING) === 'confirmed_anomaly');

$redirected = observe(doc(jl(webpage())), ['url' => 'https://example.test/altra/', 'requested' => BASE, 'redirects' => [BASE]]);
check('M8 redirect non equivalente: inconclusivo', state(run_rules(expected_of([$website]), $redirected), MISSING) === 'inconclusive');

$partialMissing = observe(doc(jl(webpage())), ['url' => BASE, 'capture' => 'partial']);
check('M9 capture parziale: inconclusivo', state(run_rules(expected_of([$website]), $partialMissing), MISSING) === 'inconclusive');

$historic = observe(doc(jl(webpage())), ['url' => BASE, 'analysis' => false]);
check('M10 storico senza analisi: inconclusivo', state(run_rules(expected_of([$website]), $historic), MISSING) === 'inconclusive'
    && state(run_rules(expected_of(null), $historic), PARSE) === 'inconclusive');

$outOfProfile = observe(doc(jl('{"@context":"https://example.org/ctx","@type":"WebSite","@id":"' . $website . '"}')), ['url' => BASE]);
check('M12 definizione fuori profilo: inconclusivo', state(run_rules(expected_of([$website]), $outOfProfile), MISSING) === 'inconclusive');

// ---------------------------------------------------------------------------
// 7. Contratti: analisi opzionale, round trip, nessun blocco grezzo serializzato
// ---------------------------------------------------------------------------

$roundTrip = new ObservedSnapshot($valid->toArray());
check('R1 round trip con analisi', json_encode($roundTrip->toArray()) === json_encode($valid->toArray()));
check('R1 nessun sorgente JSON-LD serializzato', strpos((string) json_encode($valid->toArray()), '@context') === false);
check('R2 snapshot storico senza analisi: chiave assente', !array_key_exists('jsonld_analysis', $historic->toArray()));

$badCode = $valid->toArray();
$badCode['jsonld_analysis']['errors'] = [['code' => 'json syntax', 'index' => 1, 'locator' => 'script[1]']];
$thrown = false;
try {
    new ObservedSnapshot($badCode);
} catch (\InvalidArgumentException $e) {
    $thrown = true;
}
check('R3 codice errore non stabile rifiutato', $thrown);

$missingKey = $valid->toArray();
unset($missingKey['jsonld_analysis']['profile_complete']);
$thrown = false;
try {
    new ObservedSnapshot($missingKey);
} catch (\InvalidArgumentException $e) {
    $thrown = true;
}
check('R3 analisi incompleta rifiutata', $thrown);

$thrown = false;
try {
    new ObservedValue('canonical', ['availability' => 'present', 'value' => []], 'ok');
} catch (\InvalidArgumentException $e) {
    $thrown = true;
}
check('R4 present con lista vuota resta vietato per canonical', $thrown);

$s0Node = new ObservedValue('jsonld', ['availability' => 'present', 'value' => [['id' => BASE, 'types' => ['WebPage'], 'script' => 1]]], 'ok');
check('R5 nodo nel formato S0 accettato', $s0Node->value()[0]['id'] === BASE);

$r6 = scan_report_of($truncated, array_values($rt), expected_of(null));
check('R6 ScanReport con finding JSON-LD e analisi: round trip', json_encode((new ScanReport($r6->toArray()))->toArray()) === json_encode($r6->toArray()));
check('R6 ScanReport conserva l\'analisi: errore provato, sintassi determinata, inventario incompleto',
    ($r6->toArray()['jsonld_analysis']['syntax_complete'] ?? null) === true
    && ($r6->toArray()['jsonld_analysis']['inventory_complete'] ?? null) === false);

// ---------------------------------------------------------------------------
// 8. Guardia dei callback Yoast (provider JSON-LD): ispezione di $wp_filter senza esecuzione
// ---------------------------------------------------------------------------

/** @param array<int, mixed> $functions */
function fake_hook(array $functions): object
{
    $items = [];
    foreach ($functions as $index => $function) {
        $items[] = ['function' => $function, 'accepted_args' => 1];
    }
    $hook = new \stdClass();
    $hook->callbacks = [10 => $items];
    return $hook;
}

/** Esegue la guardia privata su un registro hook fittizio; restituisce evidenza o null. */
function guard_of($registry): ?string
{
    $GLOBALS['wp_filter'] = $registry;
    $method = new \ReflectionMethod(\SeoLab\Expected\YoastExpectedProvider::class, 'unexpectedCallback');
    $method->setAccessible(true);
    $result = $method->invoke(null);
    unset($GLOBALS['wp_filter']);
    return $result;
}

const ACCESSIBILITY = 'Yoast\\WP\\SEO\\Integrations\\Front_End\\Schema_Accessibility_Feature';
const PRESENTER = 'Yoast\\WP\\SEO\\Integrations\\Front_End_Integration';
const PROTECTED_WEBPAGE = 'Yoast\\WP\\SEO\\Generators\\Schema_Generator';

check('G1 registro vuoto: nessuna evidenza', guard_of([]) === null);
check('G2 hook non controllato con callback sconosciuta: ignorato', guard_of(['the_content' => fake_hook([static function (): void {
    throw new \RuntimeException('non deve essere eseguita');
}])]) === null);
check('G3 callback ammessa sul proprio hook: conforme', guard_of(['wpseo_schema_webpage' => fake_hook([[ACCESSIBILITY, 'maybe_add_accessibility_feature']])]) === null);
check('G4 callback ammessa su hook diverso: non conforme', (string) guard_of(['wpseo_schema_article' => fake_hook([[ACCESSIBILITY, 'maybe_add_accessibility_feature']])]) === 'wpseo_schema_article: ' . ACCESSIBILITY . '::maybe_add_accessibility_feature');
$evidenceG5 = guard_of(['wpseo_schema_webpage' => fake_hook([[PROTECTED_WEBPAGE, 'protected_webpage_schema']])]);
check('G5 callback nativa non ammessa sul prefisso: classe::metodo', $evidenceG5 === 'wpseo_schema_webpage: ' . PROTECTED_WEBPAGE . '::protected_webpage_schema', (string) $evidenceG5);
$evidenceG6 = guard_of(['wpseo_json_ld' => fake_hook([static function (): void {
    throw new \RuntimeException('non deve essere eseguita');
}])]);
check('G6 closure sul hook esatto: non identificabile, mai eseguita', $evidenceG6 === 'wpseo_json_ld: callback non identificabile', (string) $evidenceG6);
check('G7 funzione di stringa sul hook esatto: non conforme', guard_of(['wpseo_json_ld_output' => fake_hook(['__return_false'])]) === 'wpseo_json_ld_output: __return_false');
check('G8 prefisso pre_schema_block_type: controllato', guard_of(['wpseo_pre_schema_block_type_article' => fake_hook([[PROTECTED_WEBPAGE, 'x']])]) !== null);
check('G9 presenter robots ammesso sul proprio hook: conforme', guard_of(['wpseo_frontend_presenter_classes' => fake_hook([[PRESENTER, 'filter_robots_presenter']])]) === null);
check('G10 registro non array: evidenza di lettura', guard_of('non array') === 'registro_hook_non_leggibile');
check('G11 contenitore non oggetto: ignorato', guard_of(['wpseo_json_ld' => 'stringa']) === null);

// ---------------------------------------------------------------------------
// 9. Provider JSON-LD: negativi di contesto, noindex, schema, conditional, ID (metodo privato, meta fittizio)
// ---------------------------------------------------------------------------

function is_admin(): bool
{
    return $GLOBALS['s3_admin'] ?? false;
}

function get_permalink($postId): string
{
    return BASE;
}

function YoastSEO(): object
{
    return (object) ['classes' => new S3Classes()];
}

final class S3Conditional
{
    public function is_met()
    {
        if (($GLOBALS['s3_conditional'] ?? 'ok') === 'throw') {
            throw new \RuntimeException('conditional non verificabile');
        }
        return $GLOBALS['s3_schema_disabled'] ?? false;
    }
}

final class S3Classes
{
    public function get($name)
    {
        return new S3Conditional();
    }
}

/** Meta fittizio che registra ogni __get e __isset in $GLOBALS['s3_reads'] (ordine e nome letti). */
final class S3Meta
{
    /** @var array<string, mixed> */
    private $data;

    public function __construct($robots, $id)
    {
        $this->data = ['robots' => $robots, 'main_schema_id' => $id];
    }

    public function __get($name)
    {
        $GLOBALS['s3_reads'][] = (string) $name;
        return $this->data[$name] ?? null;
    }

    public function __isset($name)
    {
        $GLOBALS['s3_reads'][] = 'isset:' . $name;
        return isset($this->data[$name]);
    }
}

/**
 * Esegue il ramo JSON-LD del provider su un meta fittizio. $state: admin, disabled, conditional (ok|throw), filters.
 *
 * @return array{value: array<string, mixed>, reason: string, guard: ?string}
 */
function jsonld_of(array $state, $robots = ['index', 'follow'], $id = BASE . '#webpage'): array
{
    $GLOBALS['s3_admin'] = $state['admin'] ?? true;
    $GLOBALS['s3_schema_disabled'] = $state['disabled'] ?? false;
    $GLOBALS['s3_conditional'] = $state['conditional'] ?? 'ok';
    $GLOBALS['wp_filter'] = $state['filters'] ?? [];
    $GLOBALS['s3_reads'] = [];
    $provider = new \SeoLab\Expected\YoastExpectedProvider('28.9');
    $method = new \ReflectionMethod($provider, 'jsonLd');
    $method->setAccessible(true);
    $value = $method->invoke($provider, new S3Meta($robots, $id), 1);
    unset($GLOBALS['wp_filter']);
    return ['value' => $value, 'reason' => $provider->lastJsonLdReason(), 'guard' => $provider->lastGuardEvidence(),
        'reads' => $GLOBALS['s3_reads']];
}

$j1 = jsonld_of(['admin' => false]);
check('J1 contesto non admin: unavailable', $j1['value']['availability'] === 'unavailable' && $j1['reason'] === 'context_not_admin');
$j2 = jsonld_of(['admin' => true], ['noindex', 'follow']);
check('J2 noindex: unavailable', $j2['value']['availability'] === 'unavailable' && $j2['reason'] === 'noindex');
$j3 = jsonld_of(['disabled' => true]);
check('J3 schema disattivato: unavailable con motivo schema_disabled', $j3['value']['availability'] === 'unavailable' && $j3['reason'] === 'schema_disabled');
$j4 = jsonld_of(['conditional' => 'throw']);
check('J4 conditional non verificabile: unavailable', $j4['value']['availability'] === 'unavailable' && $j4['reason'] === 'conditional_unverifiable');
$j5 = jsonld_of(['filters' => ['wpseo_schema_article' => fake_hook([[ACCESSIBILITY, 'add_accessibility_feature']])]]);
check('J5 callback non ammessa su hook di schema: unavailable con evidenza',
    $j5['value']['availability'] === 'unavailable' && $j5['reason'] === 'callback_unexpected'
    && $j5['guard'] === 'wpseo_schema_article: ' . ACCESSIBILITY . '::add_accessibility_feature');
$j6 = jsonld_of(['admin' => true], ['index'], '#webpage');
check('J6 ID relativo: unavailable', $j6['value']['availability'] === 'unavailable' && $j6['reason'] === 'schema_id_invalid');
$j7 = jsonld_of(['admin' => true], ['index'], 'https://other.test/#webpage');
check('J7 ID diverso dal permalink: unavailable', $j7['value']['availability'] === 'unavailable' && $j7['reason'] === 'schema_id_mismatch');
$j8 = jsonld_of(['admin' => true], ['index'], BASE . '#webpage');
check('J8 ID con frammento uguale al permalink: available', $j8['value']['availability'] === 'available' && $j8['reason'] === 'ok'
    && $j8['value']['value'] === ['expected_node_ids' => [BASE . '#webpage']] && $j8['value']['source'] === 'yoast:resolved');
$j9 = jsonld_of(['admin' => true], ['index'], BASE);
check('J9 ID uguale al permalink senza frammento (forma Yoast 28.9): available', $j9['value']['availability'] === 'available'
    && $j9['value']['value']['expected_node_ids'] === [BASE]);

// ---------------------------------------------------------------------------
// 10. Correzioni S-004 (R-1..R-5, M-1, M-2): ogni caso e' isolato da attempt(), cosi' un rosso non ne nasconde altri
// ---------------------------------------------------------------------------

/** Controllo isolato: un'eccezione diventa un controllo fallito con il suo nome. */
function attempt(string $label, callable $body): void
{
    try {
        $body();
    } catch (\Throwable $e) {
        check($label, false, get_class($e) . ': ' . $e->getMessage());
    }
}

/** Documento con il contenuto indicato nel body. */
function bodyDoc(string $inner): string
{
    return '<!doctype html><html lang="it"><head><meta charset="utf-8"><title>S3</title></head><body><main>'
        . $inner . '</main></body></html>';
}

/** Log degli errori come CODICE@locator, nell'ordine registrato. */
function error_list(ObservedSnapshot $observed): array
{
    $list = [];
    foreach (analysis_of($observed)['errors'] as $error) {
        $list[] = $error['code'] . '@' . $error['locator'];
    }
    return $list;
}

/** Documento con un WebPage valido per BASE e una keyword JSON-LD ($key) con valore JSON $value. */
function keyword_doc(string $key, string $value): string
{
    return doc(jl('{"@context":"https://schema.org","@type":"WebPage","@id":"' . BASE . '#webpage","' . $key . '":' . $value . '}'));
}

const EXPECTED_X = 'https://example.test/x';

// R-1: script dentro contenitori di solo testo non sono blocchi attivi.
foreach (['textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'] as $tag) {
    attempt('R1 ' . $tag . ': script di solo testo non conta', static function () use ($tag): void {
        $o = observe(bodyDoc('<' . $tag . '>' . jl('{"a":') . '</' . $tag . '>'), ['url' => BASE]);
        check('R1 ' . $tag . ': nessun blocco attivo', analysis_of($o)['blocks'] === 0
            && $o->observedValue('jsonld')->availability() === 'absent', 'blocchi ' . analysis_of($o)['blocks']);
        check('R1 ' . $tag . ': nessun errore di sintassi', state(run_rules(expected_of(null), $o), PARSE) === 'no_anomaly');
    });
}
attempt('R1 plaintext attivo: script dopo un falso end tag escluso', static function (): void {
    $o = observe(bodyDoc('<plaintext>x</plaintext>' . jl('{"a":')), ['url' => BASE]);
    check('R1 plaintext attivo: blocchi 0', analysis_of($o)['blocks'] === 0, 'blocchi ' . analysis_of($o)['blocks']);
    check('R1 plaintext attivo: nessun errore', state(run_rules(expected_of(null), $o), PARSE) === 'no_anomaly');
});
attempt('R1 script attivo dopo textarea con plaintext apparente', static function (): void {
    $o = observe(bodyDoc('<textarea><plaintext></textarea>' . jl(webpage())), ['url' => BASE]);
    check('R1 textarea: un blocco attivo', analysis_of($o)['blocks'] === 1, 'blocchi ' . analysis_of($o)['blocks']);
    check('R1 textarea: locator script[1]', nodes_of($o)[0]['locator'] === 'script[1]');
    check('R1 textarea: sintassi valida', state(run_rules(expected_of(null), $o), PARSE) === 'no_anomaly');
});

// R-1b: libxml chiude xmp e textarea prima del browser; lo script resta nel DOM ma nei byte e' testo: non mappato.
foreach (['xmp', 'textarea'] as $tag) {
    $open = $tag === 'textarea' ? '<textarea></body>' : '<xmp><ul><li>x</li></ul>';
    attempt('R1b ' . $tag . ': script nei byte dentro contenitore di testo non mappato', static function () use ($open, $tag): void {
        $o = observe(bodyDoc($open . jl('{bad') . '</' . $tag . '>' . jl(webpage())), ['url' => BASE]);
        check('R1b ' . $tag . ': JSON_UNMAPPED su script[1]', error_list($o) === ['JSON_UNMAPPED@script[1]'], implode(',', error_list($o)));
        check('R1b ' . $tag . ': PARSE inconclusiva', state(run_rules(expected_of(null), $o), PARSE) === 'inconclusive');
        check('R1b ' . $tag . ': WebPage attivo dopo il contenitore osservabile', in_array(BASE . '#webpage', array_column(nodes_of($o), 'id'), true));
    });
}
attempt('R1b maiuscole: <XMP> e </Xmp> delimitano l\'intervallo, script dopo la chiusura attivo', static function (): void {
    $o = observe(bodyDoc('<XMP>t</Xmp>' . jl('{bad')), ['url' => BASE]);
    check('R1b maiuscole: PARSE confermata', state(run_rules(expected_of(null), $o), PARSE) === 'confirmed_anomaly', implode(',', error_list($o)));
});
attempt('R1b confine del nome: <xmp-extra> non e\' un contenitore di testo', static function (): void {
    $o = observe(bodyDoc('<xmp-extra>t</xmp-extra>' . jl('{bad')), ['url' => BASE]);
    check('R1b confine: JSON_SYNTAX su script[1]', error_list($o) === ['JSON_SYNTAX@script[1]'], implode(',', error_list($o)));
    check('R1b confine: PARSE confermata', state(run_rules(expected_of(null), $o), PARSE) === 'confirmed_anomaly');
});
attempt('R1b chiusura non dimostrabile dopo </body>: script in coda non mappati', static function (): void {
    $o = observe(bodyDoc('<xmp></body>' . jl('{bad') . jl(webpage())), ['url' => BASE]);
    check('R1b coda: JSON_UNMAPPED su script[1] e script[2]', error_list($o) === ['JSON_UNMAPPED@script[1]', 'JSON_UNMAPPED@script[2]'], implode(',', error_list($o)));
    check('R1b coda: PARSE inconclusiva', state(run_rules(expected_of(null), $o), PARSE) === 'inconclusive');
});
attempt('R1b contenitore mai chiuso: libxml e browser non attivano blocchi', static function (): void {
    $o = observe(bodyDoc('<xmp>t' . jl('{bad') . jl(webpage())), ['url' => BASE]);
    check('R1b senza chiusura: nessun blocco attivo', analysis_of($o)['blocks'] === 0);
    check('R1b senza chiusura: PARSE no_anomaly', state(run_rules(expected_of(null), $o), PARSE) === 'no_anomaly');
});

// R-1c: plaintext foreign (svg/math) non e' lo stato testuale del parser HTML; plaintext HTML attivo esclude i successivi.
foreach (['svg', 'math'] as $tag) {
    attempt('R1c ' . $tag . ': plaintext foreign non esclude il WebPage successivo', static function () use ($tag): void {
        $o = observe(bodyDoc('<' . $tag . '><plaintext></plaintext></' . $tag . '>' . jl(webpage())), ['url' => BASE]);
        check('R1c ' . $tag . ': un blocco WebPage', analysis_of($o)['blocks'] === 1, 'blocchi ' . analysis_of($o)['blocks']);
        check('R1c ' . $tag . ': MISSING no_anomaly per ID esatto', state(run_rules(expected_of([BASE . '#webpage']), $o), MISSING) === 'no_anomaly');
    });
}
attempt('R1c plaintext HTML multiplo: conta solo il WebPage prima del plaintext attivo', static function (): void {
    $o = observe(bodyDoc(jl(webpage()) . '<plaintext>x</plaintext>' . jl('{bad') . jl('{bad2')), ['url' => BASE]);
    check('R1c plaintext multiplo: un blocco, locator script[1]', analysis_of($o)['blocks'] === 1 && nodes_of($o)[0]['locator'] === 'script[1]',
        'blocchi ' . analysis_of($o)['blocks']);
    check('R1c plaintext multiplo: PARSE no_anomaly', state(run_rules(expected_of([BASE . '#webpage']), $o), PARSE) === 'no_anomaly');
    check('R1c plaintext multiplo: MISSING no_anomaly', state(run_rules(expected_of([BASE . '#webpage']), $o), MISSING) === 'no_anomaly');
});

// R-2: keyword con valore strutturato: sintassi valida, inventario e profilo incompleti, codice stabile.
$keywords = [
    ['@list', '[{"@type":"Thing","@id":"' . EXPECTED_X . '"}]'],
    ['@set', '[{"@type":"Thing","@id":"' . EXPECTED_X . '"}]'],
    ['@included', '[{"@type":"Thing","@id":"' . EXPECTED_X . '"}]'],
    ['@reverse', '{"knows":{"@type":"Thing","@id":"' . EXPECTED_X . '"}}'],
    ['@nest', '{"@type":"Thing","@id":"' . EXPECTED_X . '"}'],
    ['@sconosciuta', '{"@type":"Thing","@id":"' . EXPECTED_X . '"}'],
];
foreach ($keywords as $pair) {
    attempt('R2 ' . $pair[0] . ': nodo dentro keyword non dichiarato completo', static function () use ($pair): void {
        $key = $pair[0];
        $o = observe(keyword_doc($key, $pair[1]), ['url' => BASE]);
        $r = run_rules(expected_of([EXPECTED_X]), $o);
        check('R2 ' . $key . ': sintassi valida', analysis_of($o)['syntax_complete'] === true);
        check('R2 ' . $key . ': inventario incompleto', analysis_of($o)['inventory_complete'] === false);
        check('R2 ' . $key . ': profilo incompleto', analysis_of($o)['profile_complete'] === false);
        check('R2 ' . $key . ': codice JSONLD_KEYWORD_UNSUPPORTED', in_array('JSONLD_KEYWORD_UNSUPPORTED', syntax_errors($o), true));
        check('R2 ' . $key . ': PARSE no_anomaly', state($r, PARSE) === 'no_anomaly');
        check('R2 ' . $key . ': MISSING inconclusiva', state($r, MISSING) === 'inconclusive');
        check('R2 ' . $key . ': CONTRA inconclusiva', state($r, CONTRA) === 'inconclusive');
        check('R2 ' . $key . ': round trip con il nuovo codice', json_encode((new ObservedSnapshot($o->toArray()))->toArray()) === json_encode($o->toArray()));
    });
}
attempt('R2 K7 @value letterale: inventario completo, nessuna definizione nuova', static function (): void {
    $o = observe(keyword_doc('name', '{"@value":"x","@type":"@json"}'), ['url' => BASE]);
    check('R2 K7 inventario e profilo completi', analysis_of($o)['inventory_complete'] === true && analysis_of($o)['profile_complete'] === true);
    check('R2 K7 una sola definizione', count(nodes_of($o)) === 1, 'definizioni ' . count(nodes_of($o)));
    check('R2 K7 nessun codice keyword', syntax_errors($o) === []);
});
attempt('R2 @included con due WebPage incompatibili: CONTRA inconclusiva, non assente', static function (): void {
    $inc = '[{"@context":"https://schema.org","@type":"WebPage","@id":"' . BASE . '#webpage","url":"' . BASE . '"},'
        . '{"@context":"https://schema.org","@type":"WebPage","@id":"' . BASE . '#webpage","url":"https://example.test/altra/"}]';
    $o = observe(keyword_doc('@included', $inc), ['url' => BASE]);
    check('R2 @included: CONTRA inconclusiva', state(run_rules(expected_of(null), $o), CONTRA) === 'inconclusive');
});

// R-3: tetto di 16 errori e BUDGET_NODES una sola volta; la prima prova di sintassi resta nel log.
attempt('R3 16 errori non sintattici poi sintassi: JSON_SYNTAX su script[17], massimo 16', static function (): void {
    $o = observe(doc(...array_merge(array_fill(0, 16, jl('')), [jl('{"a":')])), ['url' => BASE]);
    $codes = error_list($o);
    check('R3 PARSE confirmed', state(run_rules(expected_of(null), $o), PARSE) === 'confirmed_anomaly');
    check('R3 JSON_SYNTAX su script[17]', in_array('JSON_SYNTAX@script[17]', $codes, true), implode(',', $codes));
    check('R3 massimo 16 voci', count($codes) <= 16, 'voci ' . count($codes));
});
attempt('R3 sintassi prima di 16 errori: locator script[1] conservato', static function (): void {
    $o = observe(doc(...array_merge([jl('{"a":')], array_fill(0, 16, jl('')))), ['url' => BASE]);
    $codes = error_list($o);
    check('R3 JSON_SYNTAX resta su script[1]', in_array('JSON_SYNTAX@script[1]', $codes, true), implode(',', $codes));
    check('R3 PARSE confirmed (secondo caso)', state(run_rules(expected_of(null), $o), PARSE) === 'confirmed_anomaly');
    check('R3 massimo 16 voci (secondo caso)', count($codes) <= 16, 'voci ' . count($codes));
});
attempt('R3 273 riferimenti: BUDGET_NODES una sola volta', static function (): void {
    $refs = [];
    for ($i = 1; $i <= 273; $i++) {
        $refs[] = '{"@id":"https://example.test/r/' . $i . '"}';
    }
    $graph = '{"@context":"https://schema.org","@type":"WebPage","@id":"' . BASE . '#webpage","about":[' . implode(',', $refs) . ']}';
    $only = observe(doc(jl($graph)), ['url' => BASE]);
    $codes = error_list($only);
    check('R3 solo sforamento semantico: sintassi completa', analysis_of($only)['syntax_complete'] === true, implode(',', $codes));
    check('R3 solo sforamento semantico: BUDGET_NODES una volta', count($codes) === 1 && $codes[0] === 'BUDGET_NODES@script[1]', implode(',', $codes));
    $mixed = observe(doc(jl($graph), jl('{"a":')), ['url' => BASE]);
    $mixedCodes = error_list($mixed);
    check('R3 273 riferimenti + blocco invalido: BUDGET_NODES una volta', count(array_filter($mixedCodes, static function (string $c): bool {
        return strpos($c, 'BUDGET_NODES@') === 0;
    })) === 1, implode(',', $mixedCodes));
    check('R3 JSON_SYNTAX su script[2]', in_array('JSON_SYNTAX@script[2]', $mixedCodes, true), implode(',', $mixedCodes));
});

// R-4: excerpt limitati, identita' e locator interi (nessuna InvalidArgumentException sugli excerpt).
attempt('R4 conflitto con URL di 1100 byte: tre esiti, excerpt entro 1024', static function (): void {
    $long = 'https://example.test/' . str_repeat('a', 1100 - strlen('https://example.test/'));
    $o = observe(doc(jl(webpage()), jl(webpage($long))), ['url' => BASE]);
    $r = run_rules(expected_of(null), $o);
    check('R4 tre esiti', array_keys($r) === [PARSE, CONTRA, MISSING]);
    check('R4 CONTRA confermata', state($r, CONTRA) === 'confirmed_anomaly');
    $f = $r[CONTRA]->finding() === null ? [] : $r[CONTRA]->finding()->toArray();
    check('R4 CONTRA: URL intera conservata', strpos((string) json_encode($f, JSON_UNESCAPED_SLASHES), $long) !== false);
    check('R4 CONTRA: excerpt entro 1024 byte', $f !== [] && max(array_map(static function (array $e): int {
        return strlen((string) $e['excerpt']);
    }, $f['evidence'])) <= 1024);
});
attempt('R4 atteso mancante di 1100 byte: excerpt limitato, ID intero, altri esiti presenti', static function (): void {
    $id = 'https://example.test/' . str_repeat('c', 1100 - strlen('https://example.test/'));
    $o = observe(doc(jl(webpage())), ['url' => BASE]);
    $r = run_rules(expected_of([$id]), $o);
    check('R4 tre esiti (mancante)', array_keys($r) === [PARSE, CONTRA, MISSING]);
    check('R4 MISSING confermata', state($r, MISSING) === 'confirmed_anomaly');
    $f = $r[MISSING]->finding() === null ? [] : $r[MISSING]->finding()->toArray();
    check('R4 MISSING: ID intero conservato', strpos((string) json_encode($f, JSON_UNESCAPED_SLASHES), $id) !== false);
    check('R4 MISSING: excerpt entro 1024 byte', $f !== [] && max(array_map(static function (array $e): int {
        return strlen((string) $e['excerpt']);
    }, $f['evidence'])) <= 1024);
});

// M-1: inventario incompleto impedisce la ricerca della coppia: CONTRA inconclusiva.
attempt('M1 coppia valida con blocco extra a inventario incompleto: CONTRA inconclusiva', static function (): void {
    $r = conflict([jl(webpage()), jl(webpage('https://example.test/altra/')), jl(nodes_graph(257))]);
    check('M1 CONTRA inconclusiva', state($r, CONTRA) === 'inconclusive', state($r, CONTRA));
});

// M-2: copertura reale di escape, errori di sintassi, ambiguita', ID relativi, contesti e locator.
attempt('M2 chiave con escape @id letta come @id', static function (): void {
    $o = observe(doc(jl('{"@context":"https://schema.org","@type":"WebPage","@id":"' . BASE . '#webpage"}')), ['url' => BASE]);
    check('M2 escape: @id riconosciuto', nodes_of($o)[0]['id'] === BASE . '#webpage');
});
attempt('M2 escape coesistente con @id: vince l\'ultimo valore', static function (): void {
    $o = observe(doc(jl('{"@context":"https://schema.org","@type":"WebPage","@id":"https://example.test/prima","@id":"' . BASE . '#webpage"}')), ['url' => BASE]);
    check('M2 escape: ultimo valore', nodes_of($o)[0]['id'] === BASE . '#webpage');
});
attempt('M2 escape url: chiave url duplicata via \\u0075rl, vince l\'ultimo valore', static function (): void {
    $o = observe(doc(jl('{"@context":"https://schema.org","@type":"WebPage","@id":"' . BASE . '#webpage","url":"https://example.test/prima/","\\u0075rl":"' . BASE . '"}')), ['url' => BASE]);
    check('M2 escape url: ID riconosciuto', nodes_of($o)[0]['id'] === BASE . '#webpage');
    check('M2 escape url: ultimo valore url', nodes_of($o)[0]['url'] === BASE, (string) (nodes_of($o)[0]['url'] ?? 'assente'));
});
attempt('M2 STATE_MISMATCH [1} confermato', static function (): void {
    $o = observe(doc(jl('[1}')), ['url' => BASE]);
    check('M2 [1}: confirmed e JSON_SYNTAX', state(run_rules(expected_of(null), $o), PARSE) === 'confirmed_anomaly' && syntax_errors($o) === ['JSON_SYNTAX']);
});
attempt('M2 CTRL_CHAR newline in stringa confermato', static function (): void {
    $o = observe(doc(jl("{\"n\":\"a\nb\"}")), ['url' => BASE]);
    check('M2 newline: confirmed e JSON_SYNTAX', state(run_rules(expected_of(null), $o), PARSE) === 'confirmed_anomaly' && syntax_errors($o) === ['JSON_SYNTAX']);
});
attempt('M2 {"n":"a</p>b"}: associazione ambigua, inconclusiva', static function (): void {
    $o = observe(doc(jl('{"n":"a</p>b"}')), ['url' => BASE]);
    check('M2 </p>: PARSE inconclusiva', state(run_rules(expected_of(null), $o), PARSE) === 'inconclusive');
});
attempt('M2 testo DOM seguito da altro end tag, non da script: UNMAPPED, inconclusiva', static function (): void {
    $o = observe(doc(jl('{"x":1}</p>')), ['url' => BASE]);
    check('M2 mappatura: JSON_UNMAPPED', syntax_errors($o) === ['JSON_UNMAPPED'], implode(',', syntax_errors($o)));
    check('M2 mappatura: PARSE inconclusiva', state(run_rules(expected_of(null), $o), PARSE) === 'inconclusive');
});
attempt('M2 P7 @id relativo: stati delle tre regole', static function (): void {
    $o = observe(doc(jl('{"@context":"https://schema.org","@type":"WebPage","@id":"#w"}')), ['url' => BASE]);
    $r = run_rules(expected_of(['https://example.test/#website']), $o);
    check('M2 P7 PARSE no_anomaly', state($r, PARSE) === 'no_anomaly');
    check('M2 P7 CONTRA no_anomaly', state($r, CONTRA) === 'no_anomaly');
    check('M2 P7 MISSING inconclusiva', state($r, MISSING) === 'inconclusive');
});
attempt('M2 P7 @id non stringa: sintassi conservata, semantica inconclusiva', static function (): void {
    $o = observe(doc(jl('{"@context":"https://schema.org","@type":"WebPage","@id":5}')), ['url' => BASE]);
    $r = run_rules(expected_of(['https://example.test/#website']), $o);
    check('M2 P7b PARSE no_anomaly', state($r, PARSE) === 'no_anomaly');
    check('M2 P7b CONTRA inconclusiva', state($r, CONTRA) === 'inconclusive');
    check('M2 P7b MISSING inconclusiva', state($r, MISSING) === 'inconclusive');
});
attempt('M2 C10 @id legato alla pagina senza frammento', static function (): void {
    $r = conflict([jl(webpage(BASE, BASE)), jl(webpage('https://example.test/altra/', BASE))]);
    check('M2 C10 confermata', state($r, CONTRA) === 'confirmed_anomaly');
});
attempt('M2 contesti http e https con slash equivalenti nel conflitto', static function (): void {
    $r = conflict([
        jl('{"@context":"http://schema.org","@type":"WebPage","@id":"' . BASE . '#webpage","url":"' . BASE . '"}'),
        jl('{"@context":"https://schema.org/","@type":"WebPage","@id":"' . BASE . '#webpage","url":"https://example.test/altra/"}'),
    ]);
    check('M2 contesti equivalenti: confermata', state($r, CONTRA) === 'confirmed_anomaly');
});
attempt('M2 atteso mancante: missing_node_ids e locator con copertura', static function (): void {
    $website = 'https://example.test/#website';
    $o = observe(doc(jl(webpage())), ['url' => BASE]);
    $r = run_rules(expected_of([$website]), $o);
    $json = (string) json_encode($r[MISSING]->finding() === null ? [] : $r[MISSING]->finding()->toArray(), JSON_UNESCAPED_SLASHES);
    check('M2 missing_node_ids contiene l\'ID atteso', strpos($json, '"missing_node_ids":["' . $website . '"]') !== false, $json);
    check('M2 locator con inventario e numero blocchi', strpos($json, 'inventario completo, blocchi: 1') !== false, $json);
});

// R-5: guardie Yoast del provider JSON-LD. Callback non ammessa che lancia se eseguita, letture registrate.
final class S3ThrowingCallback
{
    public static function run(): void
    {
        throw new \RuntimeException('callback non ammessa eseguita');
    }
}

foreach (['wpseo_json_ld_output', 'wpseo_json_ld', 'wpseo_frontend_presenters', 'wpseo_frontend_presenter_classes',
    'wpseo_frontend_presentation', 'wpseo_dynamic_permalinks_enabled', 'wpseo_schema_article',
    'wpseo_pre_schema_block_type_article'] as $hook) {
    attempt('R5 guardia ' . $hook, static function () use ($hook): void {
        $r = jsonld_of(['admin' => true, 'filters' => [$hook => fake_hook([['S3ThrowingCallback', 'run']])]]);
        check('R5 ' . $hook . ': unavailable', $r['value']['availability'] === 'unavailable');
        check('R5 ' . $hook . ': motivo callback_unexpected', $r['reason'] === 'callback_unexpected', (string) $r['reason']);
        check('R5 ' . $hook . ': evidenza hook e callback', $r['guard'] === $hook . ': S3ThrowingCallback::run', (string) $r['guard']);
        check('R5 ' . $hook . ': nessuna lettura di main_schema_id', array_intersect($r['reads'], ['main_schema_id', 'isset:main_schema_id']) === []);
        check('R5 ' . $hook . ': nessuna lettura di schema o site_url', array_intersect($r['reads'], ['schema', 'isset:schema', 'site_url', 'isset:site_url']) === []);
    });
}
attempt('R5 callback nativa ammessa su wpseo_schema_webpage: prosegue', static function (): void {
    $r = jsonld_of(['admin' => true, 'filters' => ['wpseo_schema_webpage' => fake_hook([[ACCESSIBILITY, 'maybe_add_accessibility_feature']])]]);
    check('R5 ammessa 1: reason ok', $r['reason'] === 'ok', (string) $r['reason']);
    check('R5 ammessa 1: main_schema_id letto', in_array('main_schema_id', $r['reads'], true));
});
attempt('R5 callback nativa ammessa su wpseo_frontend_presenter_classes: prosegue', static function (): void {
    $r = jsonld_of(['admin' => true, 'filters' => ['wpseo_frontend_presenter_classes' => fake_hook([[PRESENTER, 'filter_robots_presenter']])]]);
    check('R5 ammessa 2: reason ok', $r['reason'] === 'ok', (string) $r['reason']);
    check('R5 ammessa 2: main_schema_id letto', in_array('main_schema_id', $r['reads'], true));
});
attempt('R5 registro sano in admin: main_schema_id letto, nessun schema o site_url', static function (): void {
    $r = jsonld_of(['admin' => true]);
    check('R5 sano: ID disponibile', $r['value']['availability'] === 'available');
    check('R5 sano: letture ammesse', in_array('main_schema_id', $r['reads'], true)
        && array_intersect($r['reads'], ['schema', 'isset:schema', 'site_url', 'isset:site_url']) === []);
});

// Ultimo: REST_REQUEST e' costante globale, non revocabile (dopo tutti i casi di contesto non REST).
define('REST_REQUEST', true);
$j10 = jsonld_of(['admin' => true]);
check('J10 REST: unavailable con motivo context_rest', $j10['value']['availability'] === 'unavailable' && $j10['reason'] === 'context_rest');

// ---------------------------------------------------------------------------
// Esito
// ---------------------------------------------------------------------------

if ($failures !== []) {
    fwrite(STDERR, "S3 jsonld: " . count($failures) . " controlli falliti:\n - " . implode("\n - ", $failures) . "\n");
    fwrite(STDOUT, "S3 jsonld: " . $passed . " controlli superati, " . count($failures) . " falliti\n");
    exit(1);
}
fwrite(STDOUT, "S3 jsonld: " . $passed . " controlli superati, 0 falliti\n");
exit(0);
