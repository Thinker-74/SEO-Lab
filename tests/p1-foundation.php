<?php

declare(strict_types=1);

/*
 * Verificatore P1.0 di SEO Lab: contratti AI opzionali, proiezione da ScanReport, validazione della risposta
 * e gate WordPress Core. Uso: php tests/p1-foundation.php. Nessuna rete e nessun WordPress: le funzioni WordPress
 * sono stub. Ogni caso e' isolato: un errore e' una riga FAIL con il nome. I casi del gate runtime girano in
 * processi figli, uno per scenario, perche' PHP non puo' annullare la definizione di una funzione.
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
        'SeoLab\\Ai\\' => 'src/Ai/',
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

use SeoLab\Ai\ReviewRequest;
use SeoLab\Ai\ReviewResult;
use SeoLab\Contracts\ScanReport;

// ---------------------------------------------------------------------------
// Helper di test
// ---------------------------------------------------------------------------

/** Report grezzo della prima scansione di una fixture P0 (prefisso del file, es. "07"). */
function p1_fixture_report(string $prefix): array
{
    $files = glob(__DIR__ . '/fixtures/' . $prefix . '-*.json');
    if ($files === false || count($files) !== 1) {
        throw new RuntimeException('fixture ' . $prefix . ' non trovata');
    }
    $fx = json_decode((string) file_get_contents($files[0]), true);
    return $fx['scans'][0]['report'];
}

/** Imposta la rule_version della regola JSONLD_ENTITY_CONTRADICTION sia nel finding sia nell'esito. */
function p1_with_rule_version(array $data, string $version): array
{
    foreach ($data['findings'] as $i => $finding) {
        $data['findings'][$i]['rule_version'] = $version;
    }
    foreach ($data['rule_outcomes'] as $i => $outcome) {
        if ($outcome['rule_id'] === 'JSONLD_ENTITY_CONTRADICTION') {
            $data['rule_outcomes'][$i]['rule_version'] = $version;
        }
    }
    return $data;
}

function p1_request_from_data(array $data, string $language): ReviewRequest
{
    return ReviewRequest::fromReport(new ScanReport($data), $language);
}

function p1_request(string $language): ReviewRequest
{
    return p1_request_from_data(p1_fixture_report('07'), $language);
}

/** Fixture 07 con cinque evidenze sullo stesso finding: il payload ne mantiene quattro. */
function p1_request_five_evidences(): ReviewRequest
{
    $data = p1_fixture_report('07');
    $first = $data['findings'][0]['evidence'][0];
    $data['findings'][0]['evidence'] = [$first, $first, $first, $first, $first];
    return p1_request_from_data($data, 'it');
}

/** Payload atteso per la fixture 07 (scritto a mano, non ricavato dal codice sotto test). */
function p1_expected_payload_07(string $language): array
{
    return [
        'schema_version' => '1',
        'prompt_version' => 'review-1',
        'language' => $language,
        'findings' => [[
            'rule_id' => 'JSONLD_ENTITY_CONTRADICTION',
            'rule_version' => '1.0.0',
            'state' => 'confirmed_anomaly',
            'severity' => 'warning',
            'cause_state' => 'unknown',
            'evidence' => [
                ['ref' => 'JSONLD_ENTITY_CONTRADICTION:1', 'kind' => 'jsonld'],
                ['ref' => 'JSONLD_ENTITY_CONTRADICTION:2', 'kind' => 'jsonld'],
            ],
        ]],
    ];
}

function p1_json(array $document): string
{
    return (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function p1_item(string $kind, string $text, array $refs): array
{
    return ['kind' => $kind, 'text' => $text, 'refs' => $refs];
}

function p1_document(array $items): array
{
    return ['schema_version' => '1', 'items' => $items];
}

/** Risposta valida minima per la fixture 07: spiegazione e test suggerito. */
function p1_valid_document(): array
{
    $ref = 'JSONLD_ENTITY_CONTRADICTION';
    return p1_document([
        p1_item('explanation', 'Due URL diverse sono dichiarate per lo stesso nodo WebPage.', [$ref . ':1', $ref . ':2']),
        p1_item('suggested_test', 'Ripetere la scansione e confrontare la proprieta url del nodo.', [$ref]),
    ]);
}

/** Documento valido riempito fino a esattamente $size byte con spazi tra i token JSON. */
function p1_padded_json(int $size): string
{
    $base = p1_json(p1_valid_document());
    return '{' . str_repeat(' ', $size - strlen($base)) . substr($base, 1);
}

function p1_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function p1_throws(callable $fn, string $class, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new RuntimeException($label . ': eccezione inattesa ' . get_class($e) . ' ' . $e->getMessage());
    }
    throw new RuntimeException($label . ': nessuna eccezione, attesa ' . $class);
}

function p1_expect_invalid(string $json, ReviewRequest $request, string $label): void
{
    $result = ReviewResult::fromJson($json, $request);
    p1_assert($result->code() === 'invalid_response' && $result->items() === [], $label . ': atteso invalid_response con zero item');
}

$p1_pass = [];
$p1_fail = [];

function p1_case(string $name, callable $fn): void
{
    global $p1_pass, $p1_fail;
    try {
        $fn();
        $p1_pass[] = $name;
        echo 'PASS ' . $name . "\n";
    } catch (Throwable $e) {
        $p1_fail[] = $name;
        echo 'FAIL ' . $name . ': ' . get_class($e) . ' ' . $e->getMessage() . "\n";
    }
}

// ---------------------------------------------------------------------------
// Gate runtime: processo figlio con stub di scenario
// ---------------------------------------------------------------------------

/**
 * Figlio: argomenti <versione|-> <builder 0/1> <wp_supports_ai 0/1/-> <opt-in 0/1>. Stampa una riga JSON
 * con lo stato del gate e i contatori di invocazioni del builder e di trasferimenti HTTP (devono restare zero).
 */
function p1_runtime_child(array $args): void
{
    list($version, $builder, $supports, $enabled) = $args;
    $GLOBALS['p1_builder_calls'] = 0;
    $GLOBALS['p1_transfers'] = 0;
    if ($version !== '-') {
        $GLOBALS['wp_version'] = $version;
    }
    if ($builder === '1') {
        function wp_ai_client_prompt($prompt = null)
        {
            $GLOBALS['p1_builder_calls']++;
            return null;
        }
    }
    if ($supports !== '-') {
        $GLOBALS['p1_supports'] = $supports === '1';
        function wp_supports_ai(): bool
        {
            return $GLOBALS['p1_supports'];
        }
    }
    function wp_remote_get($url, $args = [])
    {
        $GLOBALS['p1_transfers']++;
        return false;
    }
    function wp_safe_remote_get($url, $args = [])
    {
        $GLOBALS['p1_transfers']++;
        return false;
    }

    $status = SeoLab\Ai\WordPressAiRuntime::status($enabled === '1');
    echo json_encode([
        'status' => $status,
        'builder' => $GLOBALS['p1_builder_calls'],
        'transfers' => $GLOBALS['p1_transfers'],
    ]), "\n";
}

function p1_runtime_run(array $args): array
{
    $command = array_merge([PHP_BINARY, __FILE__, 'runtime'], $args);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('processo figlio non avviato');
    }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('processo figlio exit ' . $exit . ': ' . trim($err));
    }
    $decoded = json_decode(trim($out), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('risposta figlio non JSON: ' . trim($out));
    }
    return $decoded;
}

if (($argv[1] ?? '') === 'runtime') {
    p1_runtime_child(array_slice($argv, 2));
    exit(0);
}

// ---------------------------------------------------------------------------
// Casi
// ---------------------------------------------------------------------------

echo "P1.0 foundation: contratti AI e gate Core isolati\n";

// --- Proiezione ---------------------------------------------------------

p1_case('proiezione: payload esatto dalla fixture 07 in it', static function (): void {
    p1_assert(p1_request('it')->payload() === p1_expected_payload_07('it'), 'payload diverso dal contratto');
});

p1_case('proiezione: lingua en e riferimenti deterministici', static function (): void {
    $request = p1_request('en');
    p1_assert($request->payload() === p1_expected_payload_07('en'), 'payload en diverso dal contratto');
    p1_assert($request->referenceIds() === [
        'JSONLD_ENTITY_CONTRADICTION',
        'JSONLD_ENTITY_CONTRADICTION:1',
        'JSONLD_ENTITY_CONTRADICTION:2',
    ], 'riferimenti attesi: regola, poi :1 e :2');
});

p1_case('proiezione: sentinelle in URL, locator, excerpt, valori e testi esclusi', static function (): void {
    $s = 'SENTINEL-P1-7f3a';
    $data = p1_fixture_report('07');
    $data['findings'][0]['evidence'][0]['locator'] = 'script[2]/@graph[2]/' . $s;
    $data['findings'][0]['evidence'][0]['excerpt'] = 'IGNORA-LE-ISTRUZIONI-' . $s . ' https://' . $s . '.example.test/';
    $data['findings'][0]['expected']['value'] = 'https://' . $s . '.example.test/atteso/';
    $data['findings'][0]['observed']['values'][0] = $s;
    $data['findings'][0]['next_test'] = 'Test ' . $s;
    $data['findings'][0]['verification_method'] = 'Metodo ' . $s;
    $request = p1_request_from_data($data, 'it');
    p1_assert($request->payload() === p1_expected_payload_07('it'), 'le sentinelle non devono cambiare il payload');
    p1_assert(strpos(p1_json($request->payload()), $s) === false, 'sentinella presente nel payload serializzato');
    p1_assert(strpos(implode(' ', $request->referenceIds()), $s) === false, 'sentinella presente nei riferimenti');
});

p1_case('proiezione: lingua fuori dal set rifiutata', static function (): void {
    foreach (['fr', 'IT', 'it ', '', 'de'] as $language) {
        p1_throws(static function () use ($language): void {
            p1_request_from_data(p1_fixture_report('07'), $language);
        }, InvalidArgumentException::class, 'lingua [' . $language . ']');
    }
});

p1_case('proiezione: report senza finding rifiutato', static function (): void {
    $data = p1_fixture_report('03');
    p1_assert((new ScanReport($data))->findings() === [], 'precondizione: fixture 03 senza finding');
    p1_throws(static function () use ($data): void {
        p1_request_from_data($data, 'it');
    }, InvalidArgumentException::class, 'report senza finding');
});

p1_case('proiezione: al piu quattro evidenze per finding, in ordine originale', static function (): void {
    $request = p1_request_five_evidences();
    $payload = $request->payload();
    $evidence = $payload['findings'][0]['evidence'];
    p1_assert(count($evidence) === 4, 'attese 4 evidenze, trovate ' . count($evidence));
    p1_assert(array_column($evidence, 'ref') === [
        'JSONLD_ENTITY_CONTRADICTION:1',
        'JSONLD_ENTITY_CONTRADICTION:2',
        'JSONLD_ENTITY_CONTRADICTION:3',
        'JSONLD_ENTITY_CONTRADICTION:4',
    ], 'ref delle prime quattro evidenze');
    p1_assert(!in_array('JSONLD_ENTITY_CONTRADICTION:5', $request->referenceIds(), true), 'ref oltre il cap presente');
});

p1_case('proiezione: limite 8 KiB esatto accettato e +1 rifiutato', static function (): void {
    $base = strlen(p1_json(p1_request('it')->payload()));
    // Con rule_version di d cifre il payload cresce di d - 1 byte rispetto alla versione 1.0.0.
    $digits = 8193 - $base;
    $exact = p1_request_from_data(p1_with_rule_version(p1_fixture_report('07'), str_repeat('1', $digits) . '.0.0'), 'it');
    p1_assert(strlen(p1_json($exact->payload())) === 8192, 'payload esatto: atteso 8192 byte');
    $over = str_repeat('1', $digits + 1) . '.0.0';
    p1_throws(static function () use ($over): void {
        p1_request_from_data(p1_with_rule_version(p1_fixture_report('07'), $over), 'it');
    }, InvalidArgumentException::class, 'payload 8193 byte');
});

p1_case('proiezione: input e payload immutabili', static function (): void {
    $data = p1_fixture_report('07');
    $before = serialize($data);
    $request = p1_request_from_data($data, 'it');
    p1_assert(serialize($data) === $before, 'il report di input e\' stato mutato');
    $payload = $request->payload();
    $payload['findings'][0]['rule_id'] = 'ALTERATO';
    $payload['findings'][] = 'extra';
    p1_assert($request->payload() === p1_expected_payload_07('it'), 'la copia restituita ha mutato la richiesta');
});

// --- Risposta -----------------------------------------------------------

p1_case('risposta: documento valido con spiegazione, test e ipotesi', static function (): void {
    $request = p1_request('it');
    $ref = 'JSONLD_ENTITY_CONTRADICTION';
    $result = ReviewResult::fromJson(p1_json(p1_document([
        p1_item('explanation', 'Testo di spiegazione.', [$ref . ':1']),
        p1_item('hypothesis', 'Ipotesi giustificata.', [$ref . ':2', $ref]),
        p1_item('suggested_test', 'Test suggerito.', [$ref . ':1']),
    ])), $request);
    p1_assert($result->code() === null, 'codice inatteso: ' . var_export($result->code(), true));
    p1_assert($result->items() === [
        ['kind' => 'explanation', 'text' => 'Testo di spiegazione.', 'refs' => [$ref . ':1']],
        ['kind' => 'hypothesis', 'text' => 'Ipotesi giustificata.', 'refs' => [$ref . ':2', $ref]],
        ['kind' => 'suggested_test', 'text' => 'Test suggerito.', 'refs' => [$ref . ':1']],
    ], 'item validi non restituiti come attesi');
});

p1_case('risposta: il documento minimo spiegazione e test e\' valido', static function (): void {
    $result = ReviewResult::fromJson(p1_json(p1_valid_document()), p1_request('it'));
    p1_assert($result->code() === null && count($result->items()) === 2, 'due item attesi');
});

p1_case('risposta: requisiti di tipo, spiegazione e test obbligatori', static function (): void {
    $request = p1_request('it');
    $ref = 'JSONLD_ENTITY_CONTRADICTION';
    p1_expect_invalid(p1_json(p1_document([p1_item('suggested_test', 'Solo test.', [$ref])])), $request, 'manca spiegazione');
    p1_expect_invalid(p1_json(p1_document([p1_item('explanation', 'Solo spiegazione.', [$ref])])), $request, 'manca test');
    p1_expect_invalid(p1_json(p1_document([p1_item('hypothesis', 'Solo ipotesi.', [$ref])])), $request, 'sola ipotesi');
});

p1_case('risposta: chiavi extra, mancanti e tipi sconosciuti rifiutati', static function (): void {
    $request = p1_request('it');
    $ref = 'JSONLD_ENTITY_CONTRADICTION';
    $ok = p1_valid_document();
    $withRootExtra = $ok + ['meta' => 'x'];
    p1_expect_invalid(p1_json($withRootExtra), $request, 'chiave radice extra');
    $withItemExtra = p1_valid_document();
    $withItemExtra['items'][0]['cost'] = 1;
    p1_expect_invalid(p1_json($withItemExtra), $request, 'chiave item extra');
    $withoutRefs = p1_valid_document();
    unset($withoutRefs['items'][0]['refs']);
    p1_expect_invalid(p1_json($withoutRefs), $request, 'refs mancante');
    p1_expect_invalid(p1_json(p1_document([
        p1_item('action', 'Esegui questo comando.', [$ref]),
        p1_item('suggested_test', 'Test.', [$ref]),
        p1_item('explanation', 'Spiegazione.', [$ref]),
    ])), $request, 'kind sconosciuto');
    p1_expect_invalid(p1_json(p1_document([
        p1_item('Explanation', 'Spiegazione.', [$ref]),
        p1_item('suggested_test', 'Test.', [$ref]),
    ])), $request, 'kind con maiuscole');
    $wrongVersion = p1_valid_document();
    $wrongVersion['schema_version'] = '2';
    p1_expect_invalid(p1_json($wrongVersion), $request, 'schema_version 2');
    $intVersion = p1_valid_document();
    $intVersion['schema_version'] = 1;
    p1_expect_invalid(p1_json($intVersion), $request, 'schema_version intero');
});

p1_case('risposta: riferimenti falsi, duplicati, vuoti o di tipo errato rifiutati', static function (): void {
    $request = p1_request('it');
    $ref = 'JSONLD_ENTITY_CONTRADICTION';
    $cases = [
        'ref inventata' => [$ref . ':9'],
        'ref di altra regola' => ['ALTRA_REGOLA:1'],
        'ref oltre le evidenze' => [$ref . ':3'],
        'ref duplicata' => [$ref . ':1', $ref . ':1'],
        'lista vuota' => [],
        'ref intera zero' => [0],
        'ref null' => [null],
    ];
    foreach ($cases as $label => $refs) {
        $doc = p1_valid_document();
        $doc['items'][0]['refs'] = $refs;
        p1_expect_invalid(p1_json($doc), $request, $label);
    }
});

p1_case('risposta: massimo quattro riferimenti per item', static function (): void {
    $request = p1_request_five_evidences();
    $ref = 'JSONLD_ENTITY_CONTRADICTION';
    $four = [$ref, $ref . ':1', $ref . ':2', $ref . ':3'];
    $five = [$ref, $ref . ':1', $ref . ':2', $ref . ':3', $ref . ':4'];
    $ok = p1_document([
        p1_item('explanation', 'Spiegazione.', $four),
        p1_item('suggested_test', 'Test.', [$ref]),
    ]);
    $result = ReviewResult::fromJson(p1_json($ok), $request);
    p1_assert($result->code() === null && count($result->items()) === 2, 'quattro riferimenti distinti devono essere accettati');
    $doc = $ok;
    $doc['items'][0]['refs'] = $five;
    p1_expect_invalid(p1_json($doc), $request, 'cinque riferimenti');
});

p1_case('risposta: testo vuoto, con controlli o oltre 768 byte rifiutato', static function (): void {
    $request = p1_request('it');
    $texts = [
        'vuoto' => '',
        'solo spazi' => '   ',
        'a capo' => "riga\nnuova",
        'tabulazione' => "a\tb",
        'NUL escaped' => "a\x00b",
        'DEL' => "a\x7Fb",
    ];
    foreach ($texts as $label => $text) {
        $doc = p1_valid_document();
        $doc['items'][0]['text'] = $text;
        p1_expect_invalid(p1_json($doc), $request, 'testo ' . $label);
    }
    $doc = p1_valid_document();
    $doc['items'][0]['text'] = str_repeat('a', 768);
    p1_assert(ReviewResult::fromJson(p1_json($doc), $request)->code() === null, '768 byte devono essere accettati');
    $doc['items'][0]['text'] = str_repeat('a', 769);
    p1_expect_invalid(p1_json($doc), $request, '769 byte');
    $doc['items'][0]['text'] = str_repeat("\xC3\xA8", 384);
    p1_assert(strlen($doc['items'][0]['text']) === 768, 'precondizione: 768 byte multibyte');
    p1_assert(ReviewResult::fromJson(p1_json($doc), $request)->code() === null, '768 byte multibyte devono essere accettati');
    $doc['items'][0]['text'] = str_repeat("\xC3\xA8", 385);
    p1_expect_invalid(p1_json($doc), $request, '770 byte multibyte');
});

p1_case('risposta: numero di item tra uno e otto', static function (): void {
    $request = p1_request('it');
    $ref = 'JSONLD_ENTITY_CONTRADICTION';
    $eight = [
        p1_item('explanation', 'Spiegazione.', [$ref]),
        p1_item('suggested_test', 'Test.', [$ref]),
    ];
    for ($i = 0; $i < 6; $i++) {
        $eight[] = p1_item('hypothesis', 'Ipotesi ' . $i . '.', [$ref]);
    }
    p1_assert(ReviewResult::fromJson(p1_json(p1_document($eight)), $request)->code() === null, 'otto item devono essere accettati');
    $nine = $eight;
    $nine[] = p1_item('hypothesis', 'Ipotesi in eccesso.', [$ref]);
    p1_expect_invalid(p1_json(p1_document($nine)), $request, 'nove item');
    p1_expect_invalid(p1_json(p1_document([])), $request, 'zero item');
});

p1_case('risposta: JSON non conforme rifiutato (cornice, forma, encoding, limite)', static function (): void {
    $request = p1_request('it');
    $ref = 'JSONLD_ENTITY_CONTRADICTION';
    p1_expect_invalid('```json' . "\n" . p1_json(p1_valid_document()) . "\n" . '```', $request, 'cornice Markdown');
    p1_expect_invalid('[' . p1_json(p1_valid_document()) . ']', $request, 'radice lista');
    p1_expect_invalid('"testo"', $request, 'radice stringa');
    p1_expect_invalid('null', $request, 'radice null');
    p1_expect_invalid('{"schema_version":"1","items":', $request, 'JSON troncato');
    p1_expect_invalid('{"schema_version":"1","items":{"0":' . json_encode(p1_item('explanation', 'Spiegazione.', [$ref])) . ',"1":' . json_encode(p1_item('suggested_test', 'Test.', [$ref])) . '}}', $request, 'items come oggetto');
    $template = '{"schema_version":"1","items":[{"kind":"explanation","text":"ab__T__cd","refs":["' . $ref . '"]},{"kind":"suggested_test","text":"Test.","refs":["' . $ref . '"]}]}';
    p1_expect_invalid(str_replace('__T__', "\xC3\x28", $template), $request, 'UTF-8 non valido');
    p1_assert(ReviewResult::fromJson(str_replace('__T__', '', $template), $request)->code() === null, 'template base deve essere valido');
    p1_expect_invalid(str_replace('"refs":["' . $ref . '"]}]}', '"refs":{"0":"' . $ref . '"}}]}', $template), $request, 'refs come oggetto');
    p1_expect_invalid(str_replace('"refs":["' . $ref . '"]}]}', '"refs":[0]}]}', $template), $request, 'ref intera');
    p1_expect_invalid(p1_padded_json(12289), $request, 'JSON 12289 byte');
    $exact = p1_padded_json(12288);
    p1_assert(strlen($exact) === 12288, 'precondizione: JSON esatto 12288 byte');
    p1_assert(ReviewResult::fromJson($exact, $request)->code() === null, 'JSON 12288 byte deve essere accettato');
});

p1_case('risposta: schema_version e chiavi radice esatte', static function (): void {
    $request = p1_request('it');
    $noVersion = p1_valid_document();
    unset($noVersion['schema_version']);
    p1_expect_invalid(p1_json($noVersion), $request, 'schema_version mancante');
    $noItems = ['schema_version' => '1'];
    p1_expect_invalid(p1_json($noItems), $request, 'items mancante');
});

p1_case('risposta: la richiesta non cambia dopo la validazione', static function (): void {
    $request = p1_request('it');
    $payload = $request->payload();
    $refs = $request->referenceIds();
    ReviewResult::fromJson(p1_json(p1_valid_document()), $request);
    p1_assert($request->payload() === $payload && $request->referenceIds() === $refs, 'richiesta mutata dalla validazione');
});

p1_case('risultato: codici di errore chiusi e senza contenuto', static function (): void {
    $expected = [
        'disabled', 'unsupported_runtime', 'core_disabled', 'unconfigured', 'model_unavailable',
        'invalid_input', 'invalid_response', 'timeout', 'rate_limited', 'budget_exhausted', 'provider_error',
        'output_truncated',
    ];
    p1_assert(ReviewResult::ERROR_CODES === $expected, 'elenco codici errore diverso dal contratto');
    foreach ($expected as $code) {
        $failure = ReviewResult::failure($code);
        p1_assert($failure->code() === $code && $failure->items() === [], 'failure(' . $code . ') con contenuto');
    }
    p1_throws(static function (): void {
        ReviewResult::failure('codice_inventato');
    }, InvalidArgumentException::class, 'codice fuori elenco');
});

// --- Contratti e provider -----------------------------------------------

p1_case('provider: firma review(ReviewRequest): ReviewResult', static function (): void {
    $method = new ReflectionMethod(SeoLab\Ai\ReviewProvider::class, 'review');
    $params = $method->getParameters();
    p1_assert(count($params) === 1 && $params[0]->getType() !== null
        && $params[0]->getType()->getName() === ReviewRequest::class, 'parametro atteso ReviewRequest');
    p1_assert($method->getReturnType() !== null && $method->getReturnType()->getName() === ReviewResult::class, 'ritorno atteso ReviewResult');
});

p1_case('provider: sorgente AI senza chiamate di rete o builder', static function (): void {
    $foundationFiles = [
        'ReviewProvider.php',
        'ReviewRequest.php',
        'ReviewResult.php',
        'WordPressAiRuntime.php',
    ];
    foreach ($foundationFiles as $name) {
        $file = dirname(__DIR__) . '/src/Ai/' . $name;
        p1_assert(is_file($file), $name . ' manca dai fondamenti P1.0');
        $source = (string) file_get_contents($file);
        p1_assert(preg_match('/wp_ai_client_prompt\s*\(/', $source) !== 1, basename($file) . ' invoca il builder');
        p1_assert(preg_match('/wp_(safe_)?remote_|curl_|fsockopen|stream_socket|OpenAI|Anthropic/i', $source) !== 1, basename($file) . ' contiene canali di rete');
    }
});

// --- Gate runtime (processi figli) --------------------------------------

$runtimeCases = [
    'runtime: opt-in off prima di qualsiasi ispezione' => [['7.1.5', '1', '1', '0'], 'disabled'],
    'runtime: versione assente' => [['-', '1', '1', '1'], 'unsupported_runtime'],
    'runtime: versione 6.9.1' => [['6.9.1', '1', '1', '1'], 'unsupported_runtime'],
    'runtime: manca wp_ai_client_prompt' => [['7.1.5', '0', '1', '1'], 'unsupported_runtime'],
    'runtime: manca wp_supports_ai' => [['7.1.5', '1', '-', '1'], 'unsupported_runtime'],
    'runtime: Core con AI disabilitata' => [['7.1.5', '1', '0', '1'], 'core_disabled'],
    'runtime: 7.0.0 con simboli presenti' => [['7.0.0', '1', '1', '1'], 'core_available'],
    'runtime: 7.1.5 con simboli presenti' => [['7.1.5', '1', '1', '1'], 'core_available'],
];
foreach ($runtimeCases as $name => $case) {
    p1_case($name, static function () use ($case): void {
        $result = p1_runtime_run($case[0]);
        p1_assert($result['status'] === $case[1], 'stato ' . $result['status'] . ', atteso ' . $case[1]);
        p1_assert($result['builder'] === 0, 'builder invocato ' . $result['builder'] . ' volte');
        p1_assert($result['transfers'] === 0, 'trasferimenti HTTP ' . $result['transfers']);
    });
}

// Il doppio di test implementa l'interfaccia: va per ultimo perche' un'interfaccia mancante e' fatale in PHP.
p1_case('doppio di test: ReviewResult senza toccare la richiesta', static function (): void {
    $request = p1_request('it');
    $before = $request->payload();
    $double = new class implements SeoLab\Ai\ReviewProvider {
        public function review(ReviewRequest $request): ReviewResult
        {
            return ReviewResult::fromJson(p1_json(p1_valid_document()), $request);
        }
    };
    $result = $double->review($request);
    p1_assert($result->code() === null && count($result->items()) === 2, 'doppio: esito valido atteso');
    p1_assert($request->payload() === $before, 'doppio: richiesta mutata');
});

// ---------------------------------------------------------------------------

$total = count($p1_pass) + count($p1_fail);
if ($p1_fail !== []) {
    echo 'FAIL p1-foundation: ' . count($p1_fail) . ' casi su ' . $total . "\n";
    exit(1);
}
echo 'OK p1-foundation: ' . count($p1_pass) . '/' . $total . " casi superati\n";
