<?php

declare(strict_types=1);

/*
 * Sonda P1.1 sul trasporto WordPress Core reale: WP_AI_Client_HTTP_Client, RequestOptions e SDK php-ai-client del
 * root WordPress passato come argomento. Non e' nella CI PHP 7.4: gira nel container wordpress:php8.4-apache con
 * rete spenta. Uso: php tests/p1-core-http.php /usr/src/wordpress
 * Non avvia WordPress: carica versione, classe errore, SDK e trasporto. wp_safe_remote_request e' un doppio che
 * applica http_request_args e pre_http_request nello stesso ordine di WP_Http e non esce mai in rete.
 */

if ($argc < 2 || !is_file($argv[1] . '/wp-includes/php-ai-client/autoload.php')) {
    fwrite(STDERR, "uso: php tests/p1-core-http.php <root WordPress>\n");
    exit(2);
}

$root = rtrim($argv[1], '/');
define('ABSPATH', $root . '/');
define('WPINC', 'wp-includes');

require ABSPATH . 'wp-includes/version.php';
require ABSPATH . 'wp-includes/class-wp-error.php';
require ABSPATH . 'wp-includes/php-ai-client/autoload.php';
require ABSPATH . 'wp-includes/ai-client/adapters/class-wp-ai-client-http-client.php';

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'SeoLab\\Ai\\') === 0) {
        $file = dirname(__DIR__) . '/src/Ai/' . substr($class, strlen('SeoLab\\Ai\\')) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use SeoLab\Ai\WordPressReviewHttpScope;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Exception\NetworkException;
use WordPress\AiClientDependencies\Nyholm\Psr7\Factory\Psr17Factory;

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

/** Come WP_Http: prima http_request_args, poi pre_http_request. Senza corto circuito la rete non viene mai usata. */
function wp_safe_remote_request($url, $args = [])
{
    $args = apply_filters('http_request_args', $args, $url);
    $pre = apply_filters('pre_http_request', false, $args, $url);
    if ($pre !== false) {
        return $pre;
    }
    $GLOBALS['probe']['sent'][] = ['url' => $url, 'args' => $args];
    return ($GLOBALS['probe']['responder'])($url, $args);
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

$versions = sprintf('WordPress %s, PHP %s, sonda %s', $wp_version, PHP_VERSION, $root);
echo 'Versioni effettive: ' . $versions . "\n";
echo ($fail === [] ? 'OK' : 'FAIL') . ' p1-core-http: ' . count($pass) . '/' . (count($pass) + count($fail)) . " casi\n";
exit($fail === [] ? 0 : 1);
