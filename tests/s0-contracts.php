<?php

declare(strict_types=1);

/*
 * Verificatore S0 di SEO Lab: contratti in src/Contracts e dieci fixture in tests/fixtures.
 * Uso: php tests/s0-contracts.php
 * Uscita 0 solo se tutti i controlli passano. Ogni warning, notice o deprecation e' un errore.
 * Le fixture leggono i report solo tramite i contratti (ScanReport::toArray), non tramite copie del JSON.
 */

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

spl_autoload_register(static function (string $class): void {
    $prefix = 'SeoLab\\Contracts\\';
    if (strpos($class, $prefix) === 0) {
        $file = dirname(__DIR__) . '/src/Contracts/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use SeoLab\Contracts\ExpectedValue;
use SeoLab\Contracts\Finding;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\RuleCatalog;
use SeoLab\Contracts\RuleDiff;
use SeoLab\Contracts\ScanReport;
use SeoLab\Contracts\States;

const FIXTURE_COUNT = 10;

// Matrice degli esiti: copiata dalla tabella di 02-executor-prompt.md (S-001). Una voce per scan.
const MATRIX = [
    '01' => ['family' => 'canonical', 'scans' => [[
        'CANONICAL_MULTIPLE_DISTINCT' => 'confirmed_anomaly',
        'CANONICAL_EXPECTED_MISMATCH' => 'inconclusive',
        'CANONICAL_MISSING_WHEN_EXPECTED' => 'no_anomaly',
    ]]],
    '02' => ['family' => 'canonical', 'scans' => [[
        'CANONICAL_MULTIPLE_DISTINCT' => 'no_anomaly',
        'CANONICAL_EXPECTED_MISMATCH' => 'confirmed_anomaly',
        'CANONICAL_MISSING_WHEN_EXPECTED' => 'no_anomaly',
    ]]],
    '03' => ['family' => 'canonical', 'scans' => [[
        'CANONICAL_MULTIPLE_DISTINCT' => 'no_anomaly',
        'CANONICAL_EXPECTED_MISMATCH' => 'no_anomaly',
        'CANONICAL_MISSING_WHEN_EXPECTED' => 'no_anomaly',
    ]]],
    '04' => ['family' => 'robots', 'effect' => 'noindex', 'scans' => [[
        'ROBOTS_EFFECTIVE_MISMATCH' => 'confirmed_anomaly',
        'ROBOTS_UNEXPECTED_NOINDEX' => 'confirmed_anomaly',
    ]]],
    '05' => ['family' => 'robots', 'effect' => 'noindex', 'scans' => [[
        'ROBOTS_EFFECTIVE_MISMATCH' => 'confirmed_anomaly',
        'ROBOTS_UNEXPECTED_NOINDEX' => 'confirmed_anomaly',
    ]]],
    '06' => ['family' => 'robots', 'effect' => 'noindex', 'scans' => [[
        'ROBOTS_EFFECTIVE_MISMATCH' => 'no_anomaly',
        'ROBOTS_UNEXPECTED_NOINDEX' => 'no_anomaly',
    ]]],
    '07' => ['family' => 'jsonld', 'scans' => [[
        'JSONLD_PARSE_ERROR' => 'no_anomaly',
        'JSONLD_ENTITY_CONTRADICTION' => 'confirmed_anomaly',
        'JSONLD_EXPECTED_NODE_MISSING' => 'no_anomaly',
    ]]],
    '08' => ['family' => 'jsonld', 'scans' => [[
        'JSONLD_PARSE_ERROR' => 'no_anomaly',
        'JSONLD_ENTITY_CONTRADICTION' => 'no_anomaly',
        'JSONLD_EXPECTED_NODE_MISSING' => 'no_anomaly',
    ]]],
    '09' => ['family' => 'jsonld', 'scans' => [[
        'JSONLD_PARSE_ERROR' => 'no_anomaly',
        'JSONLD_ENTITY_CONTRADICTION' => 'no_anomaly',
        'JSONLD_EXPECTED_NODE_MISSING' => 'no_anomaly',
    ]]],
    '10' => ['family' => 'canonical', 'diff' => ['CANONICAL_EXPECTED_MISMATCH' => 'resolved'], 'scans' => [
        [
            'CANONICAL_MULTIPLE_DISTINCT' => 'no_anomaly',
            'CANONICAL_EXPECTED_MISMATCH' => 'confirmed_anomaly',
            'CANONICAL_MISSING_WHEN_EXPECTED' => 'no_anomaly',
        ],
        [
            'CANONICAL_MULTIPLE_DISTINCT' => 'no_anomaly',
            'CANONICAL_EXPECTED_MISMATCH' => 'no_anomaly',
            'CANONICAL_MISSING_WHEN_EXPECTED' => 'no_anomaly',
        ],
    ]],
];

/** @var string[] */
$errors = [];

function add_error(array &$errors, string $label, string $message): void
{
    $errors[] = $label . ': ' . $message;
}

function expect_result(string $label, callable $fn, bool $shouldPass, array &$errors): void
{
    $rejectedAsInvalid = false;
    try {
        $fn();
        $passed = true;
        $message = '';
    } catch (\InvalidArgumentException $e) {
        $passed = false;
        $rejectedAsInvalid = true;
        $message = $e->getMessage();
    } catch (\Throwable $e) {
        $passed = false;
        $message = get_class($e) . ': ' . $e->getMessage();
    }
    if ($shouldPass && !$passed) {
        add_error($errors, 'contratto ' . $label, 'atteso valido, errore: ' . $message);
    } elseif (!$shouldPass && !$rejectedAsInvalid) {
        add_error($errors, 'contratto ' . $label, 'atteso rifiuto con InvalidArgumentException, esito: ' . ($passed ? 'accettato' : $message));
    }
}

function unavailable(): array
{
    return ['availability' => 'unavailable', 'value' => null, 'source' => null];
}

function base_finding(): array
{
    return [
        'rule_id' => 'CANONICAL_EXPECTED_MISMATCH',
        'rule_version' => '1.0.0',
        'state' => 'confirmed_anomaly',
        'severity' => 'warning',
        'expected' => ['value' => 'https://example.test/pagina-02/', 'source' => 'yoast:resolved'],
        'observed' => ['values' => ['https://example.test/vecchia-02/']],
        'evidence' => [['kind' => 'html', 'locator' => "//head/link[@rel='canonical']", 'excerpt' => "<link rel='canonical' href='https://example.test/vecchia-02/'>"]],
        'cause' => ['state' => 'unknown', 'candidates' => [], 'proof' => []],
        'next_test' => 'Isolare il componente che emette il canonical',
        'verification_method' => 'Ripetere la scansione e confrontare il canonical',
    ];
}

$unitCount = 0;
$unit = function (string $label, callable $fn, bool $shouldPass) use (&$errors, &$unitCount): void {
    $unitCount++;
    expect_result($label, $fn, $shouldPass, $errors);
};

// Controlli dei contratti: separazione degli stati, invarianti, immutabilita' (solo getter).
$unit('ExpectedValue disponibile con valore vuoto', fn () => new ExpectedValue(['availability' => 'available', 'value' => '', 'source' => 'yoast:resolved']), true);
$unit('ExpectedValue indisponibile', fn () => new ExpectedValue(['availability' => 'unavailable', 'value' => null, 'source' => null]), true);
$unit('ExpectedValue indisponibile con valore', fn () => new ExpectedValue(['availability' => 'unavailable', 'value' => 'x', 'source' => null]), false);
$unit('ExpectedValue disponibile senza valore', fn () => new ExpectedValue(['availability' => 'available', 'value' => null, 'source' => 'yoast:resolved']), false);
$unit('ExpectedValue disponibilita fuori enum', fn () => new ExpectedValue(['availability' => 'confirmed_anomaly', 'value' => null, 'source' => null]), false);

$unit('ObservedSnapshot blocked valido', fn () => new ObservedSnapshot(reg_blocked()), true);
$unit('ObservedSnapshot ok con assenza valida', fn () => new ObservedSnapshot(reg_payload(['observed_values' => [
    'canonical' => ['availability' => 'absent', 'value' => []],
    'robots' => ['availability' => 'absent', 'value' => []],
    'jsonld' => ['availability' => 'absent', 'value' => []],
]])), true);
$unit('ObservedSnapshot ok senza HTTP', fn () => new ObservedSnapshot(reg_payload(['http' => ['status' => null, 'content_type' => null, 'body_sha256' => null, 'redirects' => [], 'x_robots_tag' => []]])), false);
$unit('ObservedSnapshot stato scansione fuori enum', fn () => new ObservedSnapshot(reg_payload(['capture_status' => 'confirmed'])), false);
$unit('ObservedSnapshot hash senza body', fn () => new ObservedSnapshot(reg_blocked_with(['http' => ['status' => null, 'content_type' => null, 'body_sha256' => str_repeat('a', 64), 'redirects' => [], 'x_robots_tag' => []]])), false);

$unit('Finding valido', fn () => new Finding(base_finding()), true);
$unit('Finding con stato no_anomaly', function () {
    $f = base_finding();
    $f['state'] = 'no_anomaly';
    new Finding($f);
}, false);
$unit('Finding senza evidenza', function () {
    $f = base_finding();
    $f['evidence'] = [];
    new Finding($f);
}, false);
$unit('Finding html senza locator', function () {
    $f = base_finding();
    unset($f['evidence'][0]['locator']);
    new Finding($f);
}, false);
$unit('Finding header senza nome', function () {
    $f = base_finding();
    $f['evidence'] = [['kind' => 'header', 'excerpt' => 'noindex']];
    new Finding($f);
}, false);
$unit('Finding severita fuori enum', function () {
    $f = base_finding();
    $f['severity'] = 'confirmed';
    new Finding($f);
}, false);
$unit('Finding causa unknown con candidati', function () {
    $f = base_finding();
    $f['cause'] = ['state' => 'unknown', 'candidates' => ['x'], 'proof' => []];
    new Finding($f);
}, false);
$unit('Finding causa confirmed senza prova', function () {
    $f = base_finding();
    $f['cause'] = ['state' => 'confirmed', 'candidates' => ['x'], 'proof' => []];
    new Finding($f);
}, false);
$unit('Finding causa confirmed con prova uguale all evidenza', function () {
    $f = base_finding();
    $f['cause'] = ['state' => 'confirmed', 'candidates' => ['x'], 'proof' => $f['evidence']];
    new Finding($f);
}, false);
$unit('Finding causa confirmed con prova isolante distinta', function () {
    $f = base_finding();
    $f['cause'] = ['state' => 'confirmed', 'candidates' => ['x'], 'proof' => [['kind' => 'isolation', 'method' => 'rimozione del componente in staging', 'result' => 'canonical assente']]];
    new Finding($f);
}, true);
$unit('Finding causa in slot anomalia', function () {
    $f = base_finding();
    $f['cause'] = ['state' => 'confirmed_anomaly', 'candidates' => [], 'proof' => []];
    new Finding($f);
}, false);
$unit('Finding senza verification_method', function () {
    $f = base_finding();
    $f['verification_method'] = '';
    new Finding($f);
}, false);
$unit('Finding rule_id non in catalogo', function () {
    $f = base_finding();
    $f['rule_id'] = 'CANONICAL_UNKNOWN';
    new Finding($f);
}, false);

$unit('ScanReport valido', fn () => new ScanReport(reg_payload()), true);
$unit('ScanReport capture bloccata senza findings, expected disponibile', fn () => new ScanReport(reg_blocked()), true);
$unit('ScanReport capture bloccata con anomalia confermata', fn () => new ScanReport(array_merge(reg_blocked(), [
    'rule_outcomes' => [reg_outcome('CANONICAL_EXPECTED_MISMATCH', 'confirmed_anomaly')],
    'findings' => [base_finding()],
])), false);
$unit('ScanReport scan_id non UUID', fn () => new ScanReport(reg_payload(['scan_id' => 'scan-1'])), false);
$unit('ScanReport finding duplicato', fn () => new ScanReport(reg_payload(['findings' => [base_finding(), base_finding()]])), false);
$unit('ScanReport expected senza famiglia jsonld', function () {
    $p = reg_payload();
    unset($p['expected']['resolved']['jsonld']);
    new ScanReport($p);
}, false);

// ---- Regressioni S-001 (B-1..B-6, C-1..C-3). Ogni controllo e' isolato ed ha etichetta stabile.

$regressionTotal = 0;

function reg_assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new \RuntimeException($message);
    }
}

function reg_run(array &$errors, string $label, callable $fn): void
{
    global $regressionTotal;
    $regressionTotal++;
    try {
        $fn();
    } catch (\Throwable $e) {
        add_error($errors, 'regressione ' . $label, get_class($e) . ': ' . $e->getMessage());
    }
}

function reg_reject(array &$errors, string $label, callable $fn, string $pattern): void
{
    global $regressionTotal;
    $regressionTotal++;
    try {
        $fn();
    } catch (\InvalidArgumentException $e) {
        if (preg_match($pattern, $e->getMessage()) !== 1) {
            add_error($errors, 'regressione ' . $label, 'rifiuto con messaggio non atteso: ' . $e->getMessage());
        }
        return;
    } catch (\Throwable $e) {
        add_error($errors, 'regressione ' . $label, 'atteso InvalidArgumentException, esito ' . get_class($e) . ': ' . $e->getMessage());
        return;
    }
    add_error($errors, 'regressione ' . $label, 'atteso rifiuto, esito: accettato');
}

function reg_has_key(array $data, string $key): bool
{
    foreach ($data as $k => $v) {
        if ($k === $key) {
            return true;
        }
        if (is_array($v) && reg_has_key($v, $key)) {
            return true;
        }
    }
    return false;
}

function reg_outcome(string $ruleId, string $state, string $cause = 'unknown'): array
{
    return ['rule_id' => $ruleId, 'rule_version' => '1.0.0', 'state' => $state, 'cause_state' => $cause];
}

function reg_expected(): array
{
    return [
        'configured' => ['canonical' => unavailable(), 'robots' => unavailable(), 'jsonld' => unavailable()],
        'resolved' => [
            'canonical' => ['availability' => 'available', 'value' => 'https://example.test/pagina-02/', 'source' => 'yoast:resolved'],
            'robots' => ['availability' => 'available', 'value' => 'index', 'source' => 'yoast:resolved'],
            'jsonld' => unavailable(),
        ],
    ];
}

// Report nel formato di serializzazione di spec par. 5 (capture_status, http, observed_values, rule_outcomes).
function reg_payload(array $changes = []): array
{
    $base = [
        'scan_id' => '00000000-0000-4000-8000-000000000101',
        'schema_version' => '1',
        'post_id' => 102,
        'source' => ['provider' => 'yoast', 'provider_version' => 'fixture'],
        'requested_url' => 'https://example.test/pagina-02/',
        'observed_url' => 'https://example.test/pagina-02/',
        'captured_at_utc' => '2026-11-20T17:00:00Z',
        'capture_status' => 'ok',
        'http' => ['status' => 200, 'content_type' => 'text/html; charset=UTF-8', 'body_sha256' => null, 'redirects' => [], 'x_robots_tag' => []],
        'expected' => reg_expected(),
        'observed_values' => [
            'canonical' => ['availability' => 'present', 'value' => ['https://example.test/vecchia-02/']],
            'robots' => ['availability' => 'absent', 'value' => []],
            'jsonld' => ['availability' => 'absent', 'value' => []],
        ],
        'rule_outcomes' => [
            reg_outcome('CANONICAL_MULTIPLE_DISTINCT', 'no_anomaly'),
            reg_outcome('CANONICAL_EXPECTED_MISMATCH', 'confirmed_anomaly'),
            reg_outcome('CANONICAL_MISSING_WHEN_EXPECTED', 'no_anomaly'),
        ],
        'findings' => [base_finding()],
    ];
    return array_merge($base, $changes);
}

function reg_blocked_with(array $changes): array
{
    return array_merge(reg_blocked(), $changes);
}

function reg_blocked(): array
{
    return reg_payload([
        'observed_url' => null,
        'captured_at_utc' => null,
        'capture_status' => 'blocked',
        'http' => ['status' => null, 'content_type' => null, 'body_sha256' => null, 'redirects' => [], 'x_robots_tag' => []],
        'observed_values' => [
            'canonical' => ['availability' => 'unobservable', 'value' => null],
            'robots' => ['availability' => 'unobservable', 'value' => null],
            'jsonld' => ['availability' => 'unobservable', 'value' => null],
        ],
        'rule_outcomes' => [reg_outcome('CANONICAL_EXPECTED_MISMATCH', 'not_tested')],
        'findings' => [],
    ]);
}

function reg_proof_finding(array $proof): array
{
    $f = base_finding();
    $f['cause'] = ['state' => 'confirmed', 'candidates' => ['componente-x'], 'proof' => $proof];
    return $f;
}

$evidenceItem = base_finding()['evidence'][0];

// B-1: esiti per regola, finding solo per confirmed_anomaly, Rule::evaluate restituisce RuleOutcome, RuleDiff.
reg_run($errors, 'B-1 Rule::evaluate restituisce RuleOutcome non nullable', function () {
    $type = (new \ReflectionMethod('SeoLab\Contracts\Rule', 'evaluate'))->getReturnType();
    reg_assert($type instanceof \ReflectionNamedType, 'tipo di ritorno di Rule::evaluate assente o non semplice');
    reg_assert($type->getName() === 'SeoLab\Contracts\RuleOutcome' && !$type->allowsNull(), 'tipo di ritorno di Rule::evaluate: ' . $type->getName() . ($type->allowsNull() ? ' (nullable)' : ''));
});
reg_run($errors, 'B-1 esito inconclusive letto dal report', function () {
    $report = new ScanReport(reg_payload([
        'findings' => [],
        'rule_outcomes' => [
            reg_outcome('CANONICAL_MULTIPLE_DISTINCT', 'no_anomaly'),
            reg_outcome('CANONICAL_EXPECTED_MISMATCH', 'inconclusive'),
            reg_outcome('CANONICAL_MISSING_WHEN_EXPECTED', 'no_anomaly'),
        ],
    ]));
    $states = [];
    foreach ($report->ruleOutcomes() as $o) {
        $states[$o->ruleId()] = $o->state();
    }
    reg_assert(($states['CANONICAL_EXPECTED_MISMATCH'] ?? null) === 'inconclusive', 'stato inconclusive non conservato: ' . json_encode($states));
    reg_assert(count($report->findings()) === 0, 'finding presente per esito inconclusive');
});
reg_run($errors, 'B-1 esito not_tested sintetico letto dal report', function () {
    $report = new ScanReport(reg_payload([
        'findings' => [],
        'rule_outcomes' => [reg_outcome('CANONICAL_MISSING_WHEN_EXPECTED', 'not_tested')],
    ]));
    $states = [];
    foreach ($report->ruleOutcomes() as $o) {
        $states[$o->ruleId()] = $o->state();
    }
    reg_assert(($states['CANONICAL_MISSING_WHEN_EXPECTED'] ?? null) === 'not_tested', 'stato not_tested non conservato');
});
reg_run($errors, 'B-1 confirmed con finding corrispondente', function () {
    $report = new ScanReport(reg_payload());
    reg_assert(count($report->findings()) === 1, 'findings attesi 1');
    $linked = false;
    foreach ($report->ruleOutcomes() as $o) {
        if ($o->ruleId() === 'CANONICAL_EXPECTED_MISMATCH' && $o->finding() !== null && $o->finding()->ruleVersion() === '1.0.0') {
            $linked = true;
        }
    }
    reg_assert($linked, 'esito confirmed senza finding associato');
});
reg_reject($errors, 'B-1 finding con versione incongruente rifiutato', function () {
    new ScanReport(reg_payload(['findings' => [array_merge(base_finding(), ['rule_version' => '1.0.1'])]]));
}, '/versione|version/');
reg_reject($errors, 'B-1 finding duplicato rifiutato', function () {
    new ScanReport(reg_payload(['findings' => [base_finding(), base_finding()]]));
}, '/duplicat/');
reg_reject($errors, 'B-1 confirmed senza finding rifiutato', function () {
    new ScanReport(reg_payload(['findings' => []]));
}, '/finding/');
reg_reject($errors, 'B-1 finding associato a esito non positivo rifiutato', function () {
    new ScanReport(reg_payload([
        'rule_outcomes' => [
            reg_outcome('CANONICAL_MULTIPLE_DISTINCT', 'no_anomaly'),
            reg_outcome('CANONICAL_EXPECTED_MISMATCH', 'no_anomaly'),
            reg_outcome('CANONICAL_MISSING_WHEN_EXPECTED', 'no_anomaly'),
        ],
    ]));
}, '/esito|positiv/');
reg_reject($errors, 'B-1 finding con ID senza esito rifiutato', function () {
    new ScanReport(reg_payload(['findings' => [array_merge(base_finding(), ['rule_id' => 'CANONICAL_MULTIPLE_DISTINCT'])]]));
}, '/esito|finding|ID/');
reg_reject($errors, 'B-1 finding con causa incongruente con l esito rifiutato', function () {
    new ScanReport(reg_payload(['findings' => [array_merge(base_finding(), ['cause' => ['state' => 'possible', 'candidates' => ['x'], 'proof' => []]])]]));
}, '/causa|cause/');
reg_run($errors, 'B-1 RuleDiff accetta i quattro stati diff', function () {
    foreach (['resolved', 'persists', 'changed', 'inconclusive'] as $st) {
        $d = new \SeoLab\Contracts\RuleDiff('CANONICAL_EXPECTED_MISMATCH', $st);
        reg_assert($d->ruleId() === 'CANONICAL_EXPECTED_MISMATCH' && $d->state() === $st, 'RuleDiff non conserva ID o stato ' . $st);
    }
});
reg_reject($errors, 'B-1 RuleDiff rifiuta stato fuori enum', function () {
    new \SeoLab\Contracts\RuleDiff('CANONICAL_EXPECTED_MISMATCH', 'invalid_state');
}, '/diff|stato|fuori/');

// B-2: osservato per canonical, robots, JSON-LD con disponibilita' present | absent | unobservable.
reg_run($errors, 'B-2 osservato canonical letto dal DTO', function () {
    $report = new ScanReport(reg_payload());
    $v = $report->observed()->observedValue('canonical');
    reg_assert($v->availability() === 'present', 'availability ' . $v->availability());
    reg_assert($v->value() === ['https://example.test/vecchia-02/'], 'valore canonical osservato non letto');
});
reg_run($errors, 'B-2 assenza valida su capture ok accettata', function () {
    $report = new ScanReport(reg_payload([
        'observed_values' => [
            'canonical' => ['availability' => 'absent', 'value' => []],
            'robots' => ['availability' => 'absent', 'value' => []],
            'jsonld' => ['availability' => 'absent', 'value' => []],
        ],
    ]));
    reg_assert($report->observed()->observedValue('canonical')->availability() === 'absent', 'assenza non conservata');
});
reg_run($errors, 'B-2 capture bloccata con esito unobservable', function () {
    $report = new ScanReport(reg_blocked());
    $v = $report->observed()->observedValue('canonical');
    reg_assert($v->availability() === 'unobservable' && $v->value() === null, 'capture bloccata non espone unobservable');
});
reg_reject($errors, 'B-2 assenza su capture parziale rifiutata', function () {
    new ScanReport(reg_payload([
        'capture_status' => 'partial',
        'observed_values' => [
            'canonical' => ['availability' => 'absent', 'value' => []],
            'robots' => ['availability' => 'absent', 'value' => []],
            'jsonld' => ['availability' => 'absent', 'value' => []],
        ],
    ]));
}, '/absent/');
reg_reject($errors, 'B-2 assenza su capture bloccata rifiutata', function () {
    new ScanReport(reg_blocked_with(['observed_values' => [
        'canonical' => ['availability' => 'absent', 'value' => []],
        'robots' => ['availability' => 'unobservable', 'value' => null],
        'jsonld' => ['availability' => 'unobservable', 'value' => null],
    ]]));
}, '/absent/');

// B-3: immutabilita' al confine, riferimenti annidati, oggetti, risorse, cicli.
reg_run($errors, 'B-3 ExpectedValue isolato da riferimento annidato', function () {
    $inner = 'orig';
    $ev = new ExpectedValue(['availability' => 'available', 'value' => ['k' => &$inner], 'source' => 'yoast:resolved']);
    $inner = 'mutato';
    reg_assert($ev->value()['k'] === 'orig', 'valore interno mutato dal riferimento esterno');
});
reg_run($errors, 'B-3 array restituiti da getter e serializzazione non alterano il DTO', function () {
    $ev = new ExpectedValue(['availability' => 'available', 'value' => ['k' => 'orig'], 'source' => 'yoast:resolved']);
    $copy = $ev->value();
    $copy['k'] = 'alterato';
    $dump = $ev->toArray();
    $dump['value']['k'] = 'alterato';
    reg_assert($ev->value()['k'] === 'orig', 'getter condiviso con il DTO');
});
reg_reject($errors, 'B-3 oggetto annidato rifiutato', function () {
    new ExpectedValue(['availability' => 'available', 'value' => ['k' => new \stdClass()], 'source' => 'yoast:resolved']);
}, '/oggett/');
reg_reject($errors, 'B-3 risorsa annidata rifiutata', function () {
    $h = fopen('php://memory', 'r');
    try {
        new ExpectedValue(['availability' => 'available', 'value' => ['k' => $h], 'source' => 'yoast:resolved']);
    } finally {
        fclose($h);
    }
}, '/risorsa/');
reg_reject($errors, 'B-3 struttura ciclica rifiutata', function () {
    $a = ['x' => 'v'];
    $a['self'] = &$a;
    new ExpectedValue(['availability' => 'available', 'value' => $a, 'source' => 'yoast:resolved']);
}, '/ciclic|profond/');
reg_run($errors, 'B-3 Finding isolato da riferimento annidato', function () {
    $exp = 'orig';
    $payload = base_finding();
    $payload['expected'] = ['value' => &$exp, 'source' => 'yoast:resolved'];
    $f = new Finding($payload);
    $exp = 'mutato';
    reg_assert($f->toArray()['expected']['value'] === 'orig', 'expected del Finding condiviso per riferimento');
});

// B-4: prova della causa strutturata, non vuota, distinta dall'evidenza, isolante.
reg_run($errors, 'B-4 prova strutturata distinta accettata', function () {
    new Finding(reg_proof_finding([['kind' => 'isolation', 'method' => 'rimozione del componente in staging', 'result' => 'canonical assente']]));
});
reg_reject($errors, 'B-4 prova [""] rifiutata', function () {
    new Finding(reg_proof_finding(['']));
}, '/prova/');
reg_reject($errors, 'B-4 prova [[]] rifiutata', function () {
    new Finding(reg_proof_finding([[]]));
}, '/prova/');
reg_reject($errors, 'B-4 prova copia dell evidenza a chiavi riordinate rifiutata', function () use ($evidenceItem) {
    new Finding(reg_proof_finding([[
        'excerpt' => $evidenceItem['excerpt'],
        'locator' => $evidenceItem['locator'],
        'kind' => 'html',
    ]]));
}, '/prova/');
reg_reject($errors, 'B-4 prova non isolante per causa confirmed rifiutata', function () {
    new Finding(reg_proof_finding([['kind' => 'html', 'method' => 'm', 'result' => 'r', 'locator' => '//x', 'excerpt' => 'y']]));
}, '/isolant/');

// B-5: findings e rule_outcomes come liste sequenziali, mai null, stringhe o mappe.
reg_run($errors, 'B-5 findings lista vuota accettata', function () {
    new ScanReport(reg_payload([
        'findings' => [],
        'rule_outcomes' => [
            reg_outcome('CANONICAL_MULTIPLE_DISTINCT', 'no_anomaly'),
            reg_outcome('CANONICAL_EXPECTED_MISMATCH', 'no_anomaly'),
            reg_outcome('CANONICAL_MISSING_WHEN_EXPECTED', 'no_anomaly'),
        ],
    ]));
});
reg_reject($errors, 'B-5 findings null rifiutata', function () {
    new ScanReport(reg_payload(['findings' => null]));
}, '/findings/');
reg_reject($errors, 'B-5 findings stringa rifiutata', function () {
    new ScanReport(reg_payload(['findings' => 'x']));
}, '/findings/');
reg_reject($errors, 'B-5 findings mappa rifiutata', function () {
    new ScanReport(reg_payload(['findings' => ['k' => base_finding()]]));
}, '/findings/');
reg_reject($errors, 'B-5 rule_outcomes null rifiutata', function () {
    new ScanReport(reg_payload(['rule_outcomes' => null]));
}, '/rule_outcomes/');
reg_reject($errors, 'B-5 rule_outcomes mappa rifiutata', function () {
    new ScanReport(reg_payload(['rule_outcomes' => ['CANONICAL_EXPECTED_MISMATCH' => reg_outcome('CANONICAL_EXPECTED_MISMATCH', 'confirmed_anomaly')]]));
}, '/rule_outcomes/');

// B-6: serializzazione con chiavi di spec par. 5, round trip, nessun HTML integrale.
reg_run($errors, 'B-6 round trip del report con chiavi di spec par. 5', function () {
    $a = (new ScanReport(reg_payload()))->toArray();
    foreach (['scan_id', 'schema_version', 'post_id', 'source', 'requested_url', 'observed_url', 'captured_at_utc', 'http', 'capture_status', 'findings', 'expected', 'observed_values', 'rule_outcomes'] as $key) {
        reg_assert(array_key_exists($key, $a), 'chiave mancante in toArray: ' . $key);
    }
    foreach (['status', 'content_type', 'body_sha256'] as $key) {
        reg_assert(array_key_exists($key, $a['http']), 'chiave http mancante: ' . $key);
    }
    $b = (new ScanReport($a))->toArray();
    reg_assert($a === $b, 'round trip non identico');
});
reg_run($errors, 'B-6 report bloccato con metadati parziali round trip', function () {
    $a = (new ScanReport(reg_blocked()))->toArray();
    reg_assert($a['http']['status'] === null && $a['observed_url'] === null, 'metadati mancanti non serializzati come null');
    $b = (new ScanReport($a))->toArray();
    reg_assert($a === $b, 'round trip del report bloccato non identico');
});
reg_run($errors, 'B-6 HTML integrale assente dalla serializzazione', function () {
    $a = (new ScanReport(reg_payload()))->toArray();
    reg_assert(!reg_has_key($a, 'html'), 'chiave html presente nella serializzazione');
});

// C-1: TrustedContent non attesta origine ne' sicurezza del permalink.
reg_run($errors, 'C-1 TrustedContent dichiara che non attesta origine', function () {
    $doc = (new \ReflectionClass('SeoLab\Contracts\TrustedContent'))->getDocComment();
    reg_assert($doc !== false && strpos($doc, 'non attesta') !== false, 'docblock senza dichiarazione di non attestazione');
});

// C-2: regex a corrispondenza intera con \z, nessun newline finale accettato.
reg_reject($errors, 'C-2 scan_id con newline finale rifiutato', function () {
    new ScanReport(reg_payload(['scan_id' => '00000000-0000-4000-8000-000000000101' . "\n"]));
}, '/scan_id/');
reg_reject($errors, 'C-2 URL con newline finale rifiutato', function () {
    new ScanReport(reg_payload(['requested_url' => 'https://example.test/pagina-02/' . "\n"]));
}, '/URL/');
reg_reject($errors, 'C-2 versione regola con newline finale rifiutata', function () {
    $f = base_finding();
    $f['rule_version'] = "1.0.0\n";
    new Finding($f);
}, '/rule_version/');

// C-3: excerpt limitato a 1024 byte.
reg_reject($errors, 'C-3 excerpt oltre 1024 byte rifiutato', function () {
    $f = base_finding();
    $f['evidence'][0]['excerpt'] = str_repeat('a', 1025);
    new Finding($f);
}, '/excerpt/');
reg_run($errors, 'C-3 excerpt di 1024 byte accettato', function () {
    $f = base_finding();
    $f['evidence'][0]['excerpt'] = str_repeat('a', 1024);
    new Finding($f);
});

// Fixture: presenza, numerazione, JSON valido, matrice degli esiti, hash, header, JSON-LD, diff.
$dir = __DIR__ . '/fixtures';
$files = glob($dir . '/*.json');
if ($files === false) {
    $files = [];
}
sort($files);

if (count($files) !== FIXTURE_COUNT) {
    add_error($errors, 'fixture', 'attese ' . FIXTURE_COUNT . ' fixture, trovate ' . count($files));
}

$loaded = [];
foreach ($files as $path) {
    $base = basename($path);
    if (!preg_match('/^(\d{2})-[a-z0-9-]+\.json\z/', $base, $m)) {
        add_error($errors, 'fixture ' . $base, 'nome non valido, atteso NN-*.json');
        continue;
    }
    $id = $m[1];
    if (!isset(MATRIX[$id])) {
        add_error($errors, 'fixture ' . $id, 'id non previsto dalla matrice');
        continue;
    }
    if (isset($loaded[$id])) {
        add_error($errors, 'fixture ' . $id, 'duplicata: ' . $base);
        continue;
    }
    $raw = file_get_contents($path);
    try {
        $data = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        add_error($errors, 'fixture ' . $id, 'JSON invalido in ' . $base . ': ' . $e->getMessage());
        $loaded[$id] = null;
        continue;
    }
    if (!is_array($data)) {
        add_error($errors, 'fixture ' . $id, 'radice JSON non oggetto');
        $loaded[$id] = null;
        continue;
    }
    $loaded[$id] = $data;
}

foreach (array_keys(MATRIX) as $id) {
    if (!array_key_exists($id, $loaded)) {
        add_error($errors, 'fixture ' . $id, 'mancante');
    }
}

/**
 * Blocchi JSON-LD nel documento, in ordine di comparsa (indice 1-based per script[n]).
 *
 * @return string[]
 */
function jsonld_blocks(string $html): array
{
    preg_match_all("#<script[^>]*type=['\"]application/ld\\+json['\"][^>]*>(.*?)</script>#is", $html, $matches);
    return $matches[1];
}

foreach ($loaded as $key => $fixture) {
    // Le chiavi numeriche "10" diventano int in PHP: il confronto con fixture_id richiede stringa.
    $id = (string) $key;
    if ($fixture === null) {
        continue;
    }
    $label = 'fixture ' . $id;
    $spec = MATRIX[$id];
    $family = $spec['family'];

    if (($fixture['fixture_id'] ?? null) !== $id) {
        add_error($errors, $label, 'fixture_id non coincide con il prefisso del file');
    }
    if (($fixture['family'] ?? null) !== $family) {
        add_error($errors, $label, 'famiglia attesa ' . $family);
    }
    if (!isset($fixture['scans']) || !is_array($fixture['scans']) || count($fixture['scans']) !== count($spec['scans'])) {
        add_error($errors, $label, 'numero di scan atteso ' . count($spec['scans']));
        continue;
    }

    foreach ($fixture['scans'] as $i => $scan) {
        $where = $label . ' scan ' . ($i + 1);
        if (!is_array($scan) || !isset($scan['input'], $scan['report']) || !is_array($scan['input']) || !is_array($scan['report'])) {
            add_error($errors, $where, 'atteso oggetto con input e report');
            continue;
        }
        $html = $scan['input']['html'] ?? null;
        $headers = $scan['input']['headers'] ?? null;
        if (!is_array($headers)) {
            add_error($errors, $where, 'input.headers deve essere una mappa');
            continue;
        }

        try {
            $report = new ScanReport($scan['report']);
        } catch (\Throwable $e) {
            add_error($errors, $where, $e->getMessage());
            continue;
        }

        // Esiti per regola: insieme esatto della famiglia, stati e causa unknown come da matrice.
        $outcomes = [];
        foreach ($report->ruleOutcomes() as $outcome) {
            $outcomes[$outcome->ruleId()] = $outcome;
        }
        $declared = array_keys($outcomes);
        $wanted = RuleCatalog::rulesOf($family);
        sort($declared);
        sort($wanted);
        if ($declared !== $wanted) {
            add_error($errors, $where, 'regole dichiarate [' . implode(',', $declared) . '], attese [' . implode(',', $wanted) . ']');
        }
        foreach ($spec['scans'][$i] as $ruleId => $expectedAnomaly) {
            $outcome = $outcomes[$ruleId] ?? null;
            if ($outcome === null) {
                add_error($errors, $where, 'regola attesa mancante: ' . $ruleId);
                continue;
            }
            if ($outcome->state() !== $expectedAnomaly) {
                add_error($errors, $where, $ruleId . ' = ' . $outcome->state() . ', matrice ' . $expectedAnomaly);
            }
            if ($outcome->causeState() !== States::CAUSE_UNKNOWN) {
                add_error($errors, $where, 'causa di ' . $ruleId . ' attesa unknown nelle fixture S0');
            }
        }

        // Findings: solo le anomalie confermate della matrice, con versione coerente.
        $confirmedExpected = [];
        foreach ($spec['scans'][$i] as $ruleId => $expectedAnomaly) {
            if ($expectedAnomaly === States::ANOMALY_CONFIRMED) {
                $confirmedExpected[] = $ruleId;
            }
        }
        $foundIds = [];
        foreach ($report->findings() as $finding) {
            $foundIds[] = $finding->ruleId();
        }
        sort($foundIds);
        sort($confirmedExpected);
        if ($foundIds !== $confirmedExpected) {
            add_error($errors, $where, 'findings [' . implode(',', $foundIds) . '] diversi dalla matrice [' . implode(',', $confirmedExpected) . ']');
        }

        // C-4: SHA-256 del body grezzo, controllato sul contenuto dell'input.
        if (($scan['report']['capture_status'] ?? null) === 'ok') {
            if (!is_string($html) || $html === '') {
                add_error($errors, $where, 'HTML grezzo mancante con capture ok');
            } elseif (($scan['report']['http']['body_sha256'] ?? null) !== hash('sha256', $html)) {
                add_error($errors, $where, 'body_sha256 non coincide con lo SHA-256 del body grezzo');
            }
        }

        // C-5: evidenza header riscontrata nelle occorrenze grezze (nome case-insensitive, valore esatto).
        $rawXRobots = [];
        foreach ($headers as $headerName => $headerValues) {
            if (!is_array($headerValues)) {
                add_error($errors, $where, 'input.headers: ' . $headerName . ' deve essere lista di valori');
                continue;
            }
            if (strtolower((string) $headerName) === 'x-robots-tag') {
                $rawXRobots = array_merge($rawXRobots, $headerValues);
            }
        }
        foreach ($report->findings() as $finding) {
            foreach ($finding->evidence() as $ev) {
                if (($ev['kind'] ?? null) !== 'header') {
                    continue;
                }
                $matched = false;
                foreach ($headers as $headerName => $headerValues) {
                    if (strtolower((string) $headerName) === strtolower((string) $ev['name']) && is_array($headerValues) && in_array($ev['excerpt'] ?? null, $headerValues, true)) {
                        $matched = true;
                    }
                }
                if (!$matched) {
                    add_error($errors, $where, 'evidenza header ' . $ev['name'] . ' non riscontrata nelle occorrenze grezze');
                }
                if (!in_array($ev['excerpt'] ?? null, $scan['report']['http']['x_robots_tag'] ?? [], true)) {
                    add_error($errors, $where, 'evidenza header non presente in http.x_robots_tag del report');
                }
            }
        }

        // Robots: effetto noindex per Googlebot calcolato dalle direttive osservate (bot all o googlebot).
        if (isset($spec['effect'])) {
            $robotsValue = $report->observed()->observedValue('robots')->value() ?? [];
            $effect = null;
            foreach ($robotsValue as $entry) {
                if (in_array($entry['bot'], ['all', 'googlebot'], true) && $entry['directive'] === 'noindex') {
                    $effect = 'noindex';
                }
            }
            if ($effect !== $spec['effect']) {
                add_error($errors, $where, 'effetto robots per Googlebot ' . json_encode($effect) . ', atteso ' . $spec['effect']);
            }
        }

        if ($family === 'jsonld') {
            // C-6: nodo atteso stabile, presente nell'HTML e nell'osservato, non duplicato e non nodo della prova.
            $resolvedJsonld = $scan['report']['expected']['resolved']['jsonld'] ?? [];
            $nodeIds = $resolvedJsonld['value']['expected_node_ids'] ?? [];
            if (($resolvedJsonld['availability'] ?? null) !== 'available' || $nodeIds === []) {
                add_error($errors, $where, 'nodo atteso JSON-LD non dichiarato');
            }
            $observedIds = [];
            foreach ($report->observed()->observedValue('jsonld')->value() ?? [] as $node) {
                $observedIds[] = $node['id'];
            }
            foreach ($nodeIds as $nodeId) {
                if (!is_string($html) || strpos($html, (string) $nodeId) === false) {
                    add_error($errors, $where, 'nodo atteso assente dall HTML: ' . $nodeId);
                }
                $occurrences = count(array_keys($observedIds, $nodeId, true));
                if ($occurrences !== 1) {
                    add_error($errors, $where, 'nodo atteso ' . $nodeId . ' presente ' . $occurrences . ' volte nell osservato, atteso 1 (stabile e distinto)');
                }
            }

            // C-7: locator JSON-LD a base 1: script[n]/@graph[k]/url, con excerpt uguale al valore del nodo indicato.
            $blocks = is_string($html) ? jsonld_blocks($html) : [];
            foreach ($report->findings() as $finding) {
                foreach ($finding->evidence() as $ev) {
                    if (($ev['kind'] ?? null) !== 'jsonld') {
                        continue;
                    }
                    if (!preg_match('#^script\[(\d+)\]/@graph\[(\d+)\]/url\z#', (string) $ev['locator'], $lm)) {
                        add_error($errors, $where, 'locator JSON-LD non riconosciuto: ' . $ev['locator']);
                        continue;
                    }
                    $n = (int) $lm[1];
                    $k = (int) $lm[2];
                    if ($n < 1 || $n > count($blocks)) {
                        add_error($errors, $where, 'locator script[' . $n . '] fuori dal documento (base 1, ' . count($blocks) . ' blocchi)');
                        continue;
                    }
                    $decoded = json_decode($blocks[$n - 1], true);
                    $list = is_array($decoded) && isset($decoded['@graph']) && is_array($decoded['@graph']) ? $decoded['@graph'] : [$decoded];
                    if ($k < 1 || $k > count($list) || !is_array($list[$k - 1])) {
                        add_error($errors, $where, 'locator @graph[' . $k . '] fuori dal grafo di script[' . $n . '] (base 1)');
                        continue;
                    }
                    if (($list[$k - 1]['url'] ?? null) !== ($ev['excerpt'] ?? null)) {
                        add_error($errors, $where, 'excerpt JSON-LD non coincide con url del nodo indicato da ' . $ev['locator']);
                    }
                }
            }
        }
    }

    if ($id === '10') {
        $diff = $fixture['diff'] ?? null;
        if (!is_array($diff)) {
            add_error($errors, $label, 'mappa diff mancante');
        } else {
            $builtDiff = [];
            foreach ($diff as $ruleId => $state) {
                if (RuleCatalog::familyOf((string) $ruleId) !== $family) {
                    add_error($errors, $label, 'regola diff estranea: ' . $ruleId);
                }
                try {
                    $builtDiff[(string) $ruleId] = new RuleDiff((string) $ruleId, is_string($state) ? $state : '');
                } catch (\InvalidArgumentException $e) {
                    add_error($errors, $label, 'diff: ' . $e->getMessage());
                }
            }
            if ($diff !== $spec['diff']) {
                add_error($errors, $label, 'diff attesa ' . json_encode($spec['diff']));
            }
        }
        $first = $fixture['scans'][0]['report'] ?? [];
        $second = $fixture['scans'][1]['report'] ?? [];
        if (($first['post_id'] ?? null) !== ($second['post_id'] ?? null)) {
            add_error($errors, $label, 'i due scan devono avere lo stesso post_id');
        }
        if (($first['scan_id'] ?? null) === ($second['scan_id'] ?? null)) {
            add_error($errors, $label, 'i due scan devono avere scan_id distinti');
        }
        if (($first['captured_at_utc'] ?? null) === ($second['captured_at_utc'] ?? null)) {
            add_error($errors, $label, 'i due scan devono avere timestamp distinti');
        }
        $locator = null;
        foreach ($first['findings'] ?? [] as $finding) {
            if (($finding['rule_id'] ?? null) === 'CANONICAL_EXPECTED_MISMATCH') {
                $locator = $finding['evidence'][0]['locator'] ?? null;
            }
        }
        if (!is_string($locator) || $locator === '') {
            add_error($errors, $label, 'il primo scan deve conservare il locator della mismatch');
        }
    } elseif (isset($fixture['diff'])) {
        add_error($errors, $label, 'diff non prevista per questa fixture');
    }
}

$validCount = 0;
foreach (array_keys($loaded) as $id) {
    $prefix = 'fixture ' . $id;
    $bad = false;
    foreach ($errors as $error) {
        if (strpos($error, $prefix) === 0) {
            $bad = true;
            break;
        }
    }
    if (!$bad) {
        $validCount++;
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'ERRORE ' . $error . PHP_EOL);
    }
    fwrite(STDERR, count($errors) . ' errori; fixture valide: ' . $validCount . '/' . FIXTURE_COUNT . PHP_EOL);
    exit(1);
}

printf("%d/%d fixture validate\n", $validCount, FIXTURE_COUNT);
printf("%d controlli contratto superati\n", $unitCount + $regressionTotal);
exit(0);
