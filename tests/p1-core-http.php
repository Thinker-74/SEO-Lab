<?php

declare(strict_types=1);

/*
 * Sonda P1.1 sul trasporto WordPress Core reale: WP_AI_Client_HTTP_Client, RequestOptions e SDK php-ai-client del
 * root WordPress passato come argomento. Non e' nella CI PHP 7.4: gira nel container wordpress:php8.4-apache con
 * rete spenta. Uso: php tests/p1-core-http.php /usr/src/wordpress
 * Non avvia WordPress: carica versione, classe errore, SDK e trasporto. wp_safe_remote_request e' un doppio che
 * applica http_request_args e pre_http_request nello stesso ordine di WP_Http e non esce mai in rete.
 */

if ($argc < 2 || $argc > 3 || !is_file($argv[1] . '/wp-includes/php-ai-client/autoload.php')) {
    fwrite(STDERR, "uso: php tests/p1-core-http.php <root WordPress> [<root del connettore OpenAI>]\n");
    exit(2);
}

// Con il secondo argomento: connettore ufficiale, ai-client.php reale e casi del connettore (fuori dalla CI).
$connectorRoot = $argc === 3 ? rtrim($argv[2], '/') : null;
if ($connectorRoot !== null && !is_file($connectorRoot . '/src/autoload.php')) {
    fwrite(STDERR, "connettore OpenAI senza src/autoload.php\n");
    exit(2);
}

$root = rtrim($argv[1], '/');
define('ABSPATH', $root . '/');
define('WPINC', 'wp-includes');

require ABSPATH . 'wp-includes/version.php';
require ABSPATH . 'wp-includes/class-wp-error.php';
require ABSPATH . 'wp-includes/php-ai-client/autoload.php';
require ABSPATH . 'wp-includes/ai-client/adapters/class-wp-ai-client-http-client.php';
if ($connectorRoot !== null) {
    require ABSPATH . 'wp-includes/ai-client/class-wp-ai-client-prompt-builder.php';
    require ABSPATH . 'wp-includes/ai-client.php';
    require $connectorRoot . '/src/autoload.php';
}

spl_autoload_register(static function (string $class): void {
    foreach (['SeoLab\\Ai\\' => 'Ai', 'SeoLab\\Contracts\\' => 'Contracts'] as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            $file = dirname(__DIR__) . '/src/' . $dir . '/' . substr($class, strlen($prefix)) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use SeoLab\Ai\ReviewPrompt;
use SeoLab\Ai\ReviewRequest;
use SeoLab\Ai\WordPressReviewHttpScope;
use SeoLab\Ai\WordPressReviewProvider;
use SeoLab\Contracts\ScanReport;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Exception\NetworkException;
use WordPress\AiClient\Providers\Http\HttpTransporter;
use WordPress\AiClientDependencies\Nyholm\Psr7\Factory\Psr17Factory;
use WordPress\OpenAiAiProvider\Provider\OpenAiProvider;

// ---------------------------------------------------------------------------
// Funzioni WordPress minime: hook con lo stesso ordine di WP_Http, risposta sintetica
// ---------------------------------------------------------------------------

function __($text, $domain = 'default')
{
    return $text;
}

function add_filter($tag, $callback, $priority = 10, $accepted = 1): bool
{
    $GLOBALS['probe']['filters'][$tag][] = ['p' => $priority, 'cb' => $callback, 'n' => $accepted, 's' => count($GLOBALS['probe']['filters'][$tag] ?? [])];
    return true;
}

function remove_filter($tag, $callback, $priority = 10): bool
{
    foreach ($GLOBALS['probe']['filters'][$tag] ?? [] as $i => $entry) {
        if ($entry['cb'] === $callback && $entry['p'] === $priority) {
            unset($GLOBALS['probe']['filters'][$tag][$i]);
            return true;
        }
    }
    return false;
}

function apply_filters($tag, $value, ...$args)
{
    $entries = $GLOBALS['probe']['filters'][$tag] ?? [];
    usort($entries, static function ($a, $b) {
        return ($a['p'] <=> $b['p']) ?: ($a['s'] <=> $b['s']);
    });
    foreach ($entries as $entry) {
        $value = call_user_func_array($entry['cb'], array_slice(array_merge([$value], $args), 0, $entry['n']));
    }
    return $value;
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function do_action($tag, ...$args): void
{
}

function wp_remote_retrieve_response_code($response)
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
}

function wp_remote_retrieve_response_message($response)
{
    return is_array($response) ? (string) ($response['response']['message'] ?? '') : '';
}

function wp_remote_retrieve_headers($response)
{
    return is_array($response) ? ($response['headers'] ?? []) : [];
}

function wp_remote_retrieve_body($response)
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}

/** Come WP_Http: prima http_request_args, poi pre_http_request, infine http_response su una risposta non WP_Error. */
function wp_safe_remote_request($url, $args = [])
{
    $args = apply_filters('http_request_args', $args, $url);
    $pre = apply_filters('pre_http_request', false, $args, $url);
    if ($pre !== false) {
        return $pre;
    }
    $GLOBALS['probe']['sent'][] = ['url' => $url, 'args' => $args];
    $response = ($GLOBALS['probe']['responder'])($url, $args);
    return is_array($response) ? apply_filters('http_response', $response, $args, $url) : $response;
}

// ---------------------------------------------------------------------------
// Casi
// ---------------------------------------------------------------------------

$GLOBALS['probe'] = ['filters' => [], 'sent' => [], 'responder' => null];
$pass = [];
$fail = [];

function probe_case(string $name, callable $fn): void
{
    global $pass, $fail;
    $GLOBALS['probe']['sent'] = [];
    $GLOBALS['probe']['responder'] = static function (): array {
        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => 'ok', 'headers' => []];
    };
    try {
        $fn();
        $pass[] = $name;
        echo 'PASS ' . $name . "\n";
    } catch (Throwable $e) {
        $fail[] = $name;
        echo 'FAIL ' . $name . ': ' . get_class($e) . ' ' . $e->getMessage() . "\n";
    }
}

function probe_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$factory = new Psr17Factory();
$client = new WP_AI_Client_HTTP_Client($factory, $factory);

function probe_post(WP_AI_Client_HTTP_Client $client, Psr17Factory $factory, string $url, array $options)
{
    $request = $factory->createRequest('POST', $url)
        ->withHeader('Authorization', 'Bearer PROBE-KEY')
        ->withHeader('Content-Type', 'application/json')
        ->withBody($factory->createStream('{"input":"x"}'));
    return $client->sendRequestWithOptions($request, RequestOptions::fromArray($options));
}

probe_case('core: POST reale con timeout e redirect dalle RequestOptions', static function () use ($client, $factory): void {
    $response = probe_post($client, $factory, 'https://api.openai.com/v1/responses', ['timeout' => 7.5, 'maxRedirects' => 0]);
    $sent = $GLOBALS['probe']['sent'][0]['args'];
    probe_assert($sent['method'] === 'POST', 'metodo ' . $sent['method']);
    probe_assert($sent['timeout'] === 7.5, 'timeout non passato');
    probe_assert($sent['redirection'] === 0, 'redirect non passato');
    probe_assert($sent['body'] === '{"input":"x"}', 'corpo non passato');
    probe_assert(($sent['headers']['Authorization'] ?? '') === 'Bearer PROBE-KEY', 'autenticazione non passata');
    probe_assert($response->getStatusCode() === 200 && (string) $response->getBody() === 'ok', 'risposta non restituita');
});

probe_case('core: HTTP 429 resta una risposta PSR-7, non un\'eccezione di rete', static function () use ($client, $factory): void {
    $GLOBALS['probe']['responder'] = static function (): array {
        return ['response' => ['code' => 429, 'message' => 'Too Many'], 'body' => '', 'headers' => []];
    };
    $response = probe_post($client, $factory, 'https://api.openai.com/v1/responses', ['timeout' => 5.0]);
    probe_assert($response->getStatusCode() === 429, 'status ' . $response->getStatusCode());
});

probe_case('core: WP_Error di rete diventa NetworkException', static function () use ($client, $factory): void {
    $GLOBALS['probe']['responder'] = static function () {
        return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
    };
    try {
        probe_post($client, $factory, 'https://api.openai.com/v1/responses', ['timeout' => 5.0]);
        throw new RuntimeException('nessuna eccezione di rete');
    } catch (NetworkException $e) {
        probe_assert(true, '');
    }
});

probe_case('guardia sul Core reale: POST ammessa con limiti, seconda POST e host estraneo rifiutati', static function () use ($client, $factory): void {
    $scope = new WordPressReviewHttpScope('api.openai.com', static function (): int {
        return hrtime(true);
    });
    $scope->enter();
    $scope->allow('POST', '/v1/responses');
    $response = probe_post($client, $factory, 'https://api.openai.com/v1/responses', ['timeout' => 30.0, 'maxRedirects' => 5]);
    probe_assert($response->getStatusCode() === 200, 'POST legittima non inviata');
    $sent = $GLOBALS['probe']['sent'][0]['args'];
    probe_assert($sent['limit_response_size'] === 262144, 'limite risposta assente');
    probe_assert($sent['redirection'] === 0, 'redirect non azzerato dalla guardia');
    probe_assert($sent['timeout'] > 0 && $sent['timeout'] <= 12, 'timeout non limitato a 12 s');
    try {
        probe_post($client, $factory, 'https://api.openai.com/v1/responses', ['timeout' => 5.0]);
        throw new RuntimeException('seconda POST ammessa');
    } catch (NetworkException $e) {
        probe_assert($scope->reason() === WordPressReviewHttpScope::REASON_SCOPE, 'motivo perso: ' . var_export($scope->reason(), true));
    }
    $scope->allow('POST', '/v1/responses');
    try {
        probe_post($client, $factory, 'https://evil.example/v1/responses', ['timeout' => 5.0]);
        throw new RuntimeException('host estraneo ammesso');
    } catch (NetworkException $e) {
        probe_assert(count($GLOBALS['probe']['sent']) === 1, 'egress verso host estraneo');
    }
    $scope->leave();
    probe_assert(empty($GLOBALS['probe']['filters']['http_request_args']) && empty($GLOBALS['probe']['filters']['pre_http_request']), 'filtri non rimossi');
});

probe_case('guardia sul Core reale: GET catalogo senza body ammessa, seconda GET rifiutata', static function () use ($client, $factory): void {
    $scope = new WordPressReviewHttpScope('api.openai.com', static function (): int {
        return hrtime(true);
    });
    $scope->enter();
    $scope->allow('GET', '/v1/models');
    $request = $factory->createRequest('GET', 'https://api.openai.com/v1/models')
        ->withHeader('Authorization', 'Bearer PROBE-KEY');
    $response = $client->sendRequestWithOptions($request, RequestOptions::fromArray(['timeout' => 20.0, 'maxRedirects' => 4]));
    probe_assert($response->getStatusCode() === 200, 'GET legittima non inviata');
    $sent = $GLOBALS['probe']['sent'][0]['args'];
    probe_assert($sent['method'] === 'GET', 'metodo ' . $sent['method']);
    probe_assert($sent['limit_response_size'] === 262144, 'limite risposta GET assente');
    probe_assert($sent['redirection'] === 0, 'redirect GET non azzerato dalla guardia');
    probe_assert($sent['timeout'] > 0 && $sent['timeout'] <= 12, 'timeout GET non limitato a 12 s');
    try {
        $request = $factory->createRequest('GET', 'https://api.openai.com/v1/models');
        $client->sendRequestWithOptions($request, RequestOptions::fromArray(['timeout' => 5.0]));
        throw new RuntimeException('seconda GET ammessa');
    } catch (NetworkException $e) {
        probe_assert($scope->reason() === WordPressReviewHttpScope::REASON_SCOPE, 'motivo GET perso: ' . var_export($scope->reason(), true));
    }
    probe_assert(count($GLOBALS['probe']['sent']) === 1, 'seconda GET inviata');
    $scope->leave();
    probe_assert(empty($GLOBALS['probe']['filters']['http_request_args']) && empty($GLOBALS['probe']['filters']['pre_http_request']), 'filtri GET non rimossi');
});

probe_case('guardia sul Core reale: dopo leave la richiesta successiva non e\' limitata', static function () use ($client, $factory): void {
    probe_post($client, $factory, 'https://api.openai.com/v1/responses', ['timeout' => 9.0, 'maxRedirects' => 3]);
    $sent = $GLOBALS['probe']['sent'][0]['args'];
    probe_assert(!isset($sent['limit_response_size']) && $sent['timeout'] === 9.0 && $sent['redirection'] === 3, 'argomenti alterati fuori scope');
});

probe_case('troncamento sul Core reale: Anthropic max_tokens passa invariata e attiva solo il segnale della guardia', static function () use ($client, $factory): void {
    $body = json_encode([
        'id' => 'msg_probe',
        'type' => 'message',
        'role' => 'assistant',
        'content' => [['type' => 'text', 'text' => '{"schema_version":"1","items":[{"kind":"expl']],
        'stop_reason' => 'max_tokens',
        'stop_sequence' => null,
        'usage' => ['input_tokens' => 11, 'output_tokens' => 768],
    ], JSON_UNESCAPED_UNICODE);
    $GLOBALS['probe']['responder'] = static function () use ($body): array {
        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => $body, 'headers' => []];
    };
    $scope = new WordPressReviewHttpScope('api.anthropic.com', static function (): int {
        return hrtime(true);
    }, true);
    $scope->enter();
    $scope->allow('POST', '/v1/messages');
    $response = probe_post($client, $factory, 'https://api.anthropic.com/v1/messages', ['timeout' => 30.0, 'maxRedirects' => 0]);
    probe_assert($response->getStatusCode() === 200, 'status ' . $response->getStatusCode());
    probe_assert((string) $response->getBody() === $body, 'corpo alterato dal filtro di risposta');
    probe_assert($scope->truncated(), 'segnale assente sul Core reale');
    $scope->leave();
    probe_assert(empty($GLOBALS['probe']['filters']['http_request_args']) && empty($GLOBALS['probe']['filters']['pre_http_request']) && empty($GLOBALS['probe']['filters']['http_response']), 'filtri non rimossi');
    probe_assert(!$scope->truncated(), 'segnale non azzerato in leave');
});

probe_case('troncamento sul Core reale: scope OpenAI non registra il filtro di risposta', static function () use ($client, $factory): void {
    $body = '{"type":"message","content":[],"stop_reason":"max_tokens"}';
    $GLOBALS['probe']['responder'] = static function () use ($body): array {
        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => $body, 'headers' => []];
    };
    $scope = new WordPressReviewHttpScope('api.openai.com', static function (): int {
        return hrtime(true);
    });
    $scope->enter();
    $scope->allow('POST', '/v1/responses');
    $response = probe_post($client, $factory, 'https://api.openai.com/v1/responses', ['timeout' => 30.0, 'maxRedirects' => 0]);
    probe_assert((string) $response->getBody() === $body, 'corpo alterato');
    probe_assert(!$scope->truncated(), 'segnale attivato senza flag');
    $scope->leave();
    probe_assert(empty($GLOBALS['probe']['filters']['http_response']), 'filtro di risposta non rimosso');
});

// ---------------------------------------------------------------------------
// Casi del connettore OpenAI sul Core reale: WordPressReviewProvider::review() con risposta sintetica
// ---------------------------------------------------------------------------

/** Richiesta di revisione dalla fixture 07, come in tests/p1-connectors.php. */
function probe_connector_request(string $language): ReviewRequest
{
    $files = glob(__DIR__ . '/fixtures/07-*.json');
    if ($files === false || count($files) !== 1) {
        throw new RuntimeException('fixture 07 non trovata');
    }
    $fixture = json_decode((string) file_get_contents($files[0]), true);
    return ReviewRequest::fromReport(new ScanReport($fixture['scans'][0]['report']), $language);
}

/** Documento Responses: testo del messaggio di output, stato e motivo di incompletezza opzionali. */
function probe_connector_document(string $model, string $status, ?string $reason): string
{
    $ref = probe_connector_request('it')->referenceIds()[0];
    $text = json_encode([
        'schema_version' => '1',
        'items' => [
            ['kind' => 'explanation', 'text' => 'Spiegazione di prova.', 'refs' => [$ref]],
            ['kind' => 'suggested_test', 'text' => 'Test di prova.', 'refs' => [$ref]],
        ],
    ], JSON_UNESCAPED_UNICODE);
    $document = [
        'id' => 'resp_fixture',
        'object' => 'response',
        'model' => $model,
        'status' => $status,
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]],
        'usage' => ['input_tokens' => 15, 'output_tokens' => 3, 'total_tokens' => 18],
    ];
    if ($reason !== null) {
        $document['incomplete_details'] = ['reason' => $reason];
    }
    return json_encode($document, JSON_UNESCAPED_UNICODE);
}

/**
 * Esegue una revisione OpenAI con il modello dato. Il doppio di trasporto risponde al catalogo (GET) e alla
 * generazione (POST) con il documento indicato. Stampa una riga META con i valori osservati al trasporto.
 *
 * @return array{result: mixed, posts: array<int, array<string, mixed>>, gets: array<int, array<string, mixed>>, request: ReviewRequest}
 */
function probe_connector_run(string $model, string $document): array
{
    $request = probe_connector_request('it');
    $GLOBALS['probe']['responder'] = static function (string $url, array $args) use ($document): array {
        if ($url === 'https://api.openai.com/v1/models' && $args['method'] === 'GET') {
            // Il catalogo e' in cache nel processo: elenca tutti gli ID dei casi, non solo quello del caso corrente.
            $data = array_map(static function (string $id): array {
                return ['id' => $id, 'object' => 'model', 'created' => 1, 'owned_by' => 'fixture'];
            }, ['gpt-6-luna', 'gpt-6-astra', 'gpt-4o']);
            return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => json_encode(['object' => 'list', 'data' => $data])];
        }
        return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => ['content-type' => 'application/json'], 'body' => $document];
    };
    $subject = new WordPressReviewProvider(true, 'openai', $model, static function (): ?string {
        return null;
    });
    $result = $subject->review($request);

    $posts = [];
    $gets = [];
    foreach ($GLOBALS['probe']['sent'] as $entry) {
        if ($entry['args']['method'] === 'POST') {
            $posts[] = $entry;
        } elseif ($entry['args']['method'] === 'GET') {
            $gets[] = $entry;
        }
    }
    $body = $posts === [] ? [] : (json_decode((string) $posts[0]['args']['body'], true) ?? []);
    echo 'META model=' . $model . ' cap=' . var_export($body['max_output_tokens'] ?? null, true)
        . ' effort=' . var_export($body['reasoning']['effort'] ?? null, true)
        . ' GET=' . count($gets) . ' POST=' . count($posts)
        . ' esito=' . var_export($result->code(), true) . ' item=' . count($result->items()) . "\n";
    return ['result' => $result, 'posts' => $posts, 'gets' => $gets, 'request' => $request];
}

/** Il corpo della POST letto al trasporto: solo il primo tentativo conta. */
function probe_connector_body(array $run): array
{
    return json_decode((string) $run['posts'][0]['args']['body'], true);
}

if ($connectorRoot !== null) {
    $connectorRegistry = AiClient::defaultRegistry();
    $connectorFactory = new Psr17Factory();
    $connectorRegistry->setHttpTransporter(new HttpTransporter(new WP_AI_Client_HTTP_Client($connectorFactory, $connectorFactory), $connectorFactory, $connectorFactory));
    $connectorRegistry->registerProvider(OpenAiProvider::class);
    $connectorRegistry->setProviderRequestAuthentication(OpenAiProvider::class, new ApiKeyRequestAuthentication('fixture-auth'));

    probe_case('connettore: gpt-6-luna invia reasoning.effort none, cap 768, modello ed istruzioni esatti, senza strumenti', static function (): void {
        $run = probe_connector_run('gpt-6-luna', probe_connector_document('gpt-6-luna', 'completed', null));
        probe_assert($run['result']->code() === null && count($run['result']->items()) === 2, 'esito ' . var_export($run['result']->code(), true));
        probe_assert(count($run['posts']) === 1 && $run['posts'][0]['url'] === 'https://api.openai.com/v1/responses', 'POST non unica verso /v1/responses');
        $body = probe_connector_body($run);
        probe_assert(($body['reasoning']['effort'] ?? null) === 'none', 'reasoning.effort assente o diverso');
        probe_assert(($body['max_output_tokens'] ?? null) === 768, 'cap diverso da 768');
        probe_assert(($body['model'] ?? null) === 'gpt-6-luna', 'modello diverso');
        probe_assert(($body['instructions'] ?? null) === ReviewPrompt::fromRequest($run['request'])['system'], 'istruzioni diverse');
        probe_assert(!isset($body['tools']), 'strumenti presenti nel payload');
        probe_assert(empty($GLOBALS['probe']['filters']['http_request_args']) && empty($GLOBALS['probe']['filters']['pre_http_request']), 'filtri non rimossi');
    });

    probe_case('connettore: altro ID GPT-6 (gpt-6-astra) resta senza reasoning', static function (): void {
        $run = probe_connector_run('gpt-6-astra', probe_connector_document('gpt-6-astra', 'completed', null));
        probe_assert($run['result']->code() === null && count($run['result']->items()) === 2, 'esito ' . var_export($run['result']->code(), true));
        probe_assert(!array_key_exists('reasoning', probe_connector_body($run)), 'reasoning inviato a un ID diverso da Luna');
    });

    probe_case('connettore: risposta incompleta per max_output_tokens con JSON valido -> output_truncated', static function (): void {
        $run = probe_connector_run('gpt-6-luna', probe_connector_document('gpt-6-luna', 'incomplete', 'max_output_tokens'));
        probe_assert($run['result']->code() === 'output_truncated' && $run['result']->items() === [], 'codice ' . var_export($run['result']->code(), true));
        probe_assert(count($run['posts']) === 1, 'POST diverse da una');
        probe_assert(empty($GLOBALS['probe']['filters']['http_request_args']) && empty($GLOBALS['probe']['filters']['pre_http_request']), 'filtri non rimossi');
    });

    probe_case('connettore: content_filter con JSON valido -> invalid_response, nessun item, nessun usage', static function (): void {
        $run = probe_connector_run('gpt-6-luna', probe_connector_document('gpt-6-luna', 'incomplete', 'content_filter'));
        probe_assert($run['result']->code() === 'invalid_response' && $run['result']->items() === [], 'codice ' . var_export($run['result']->code(), true));
        probe_assert(count($run['posts']) === 1, 'POST diverse da una');
    });
}

$versions = sprintf('WordPress %s, PHP %s, sonda %s%s', $wp_version, PHP_VERSION, $root, $connectorRoot === null ? '' : ', connettore ' . $connectorRoot);
echo 'Versioni effettive: ' . $versions . "\n";
echo ($fail === [] ? 'OK' : 'FAIL') . ' p1-core-http: ' . count($pass) . '/' . (count($pass) + count($fail)) . " casi\n";
exit($fail === [] ? 0 : 1);
