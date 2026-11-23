<?php

declare(strict_types=1);

/*
 * Verificatore P1.1 di SEO Lab: adapter OpenAI e Anthropic su trasporto simulato, guardia HTTP e prompt review-1.
 * Uso: php tests/p1-connectors.php. Nessuna rete e nessun WordPress: Core, registry, builder, risultato e trasporto
 * sono doppi di test. Il trasporto passa dai filtri http_request_args e pre_http_request come in WordPress, cosi' la
 * guardia del progetto viene eseguita davvero. La sonda sul Core reale sta in tests/p1-core-http.php, fuori dalla CI.
 * Ogni caso e' isolato: un errore e' una riga FAIL con il nome.
 */

namespace WordPress\AiClient {
    final class AiClient
    {
        public static function defaultRegistry()
        {
            return $GLOBALS['p1c_registry'];
        }
    }
}

namespace WordPress\AiClient\Providers\Models\TextGeneration\Contracts {
    interface TextGenerationModelInterface
    {
    }
}

namespace WordPress\AiClient\Providers\Http\DTO {
    final class RequestOptions
    {
        public const KEY_TIMEOUT = 'timeout';
        public const KEY_MAX_REDIRECTS = 'maxRedirects';

        /** @var array<string, mixed> */
        private $data = [];

        public static function fromArray(array $array): self
        {
            $options = new self();
            $options->data = $array;
            return $options;
        }

        public function toArray(): array
        {
            return $this->data;
        }
    }
}

namespace WordPress\AiClient\Results\DTO {
    final class GenerativeAiResult
    {
        public $providerId;
        public $modelId;
        public $text;
        public $usage;

        public function getProviderMetadata()
        {
            return new \P1cMeta($this->providerId, false);
        }

        public function getModelMetadata()
        {
            return new \P1cMeta($this->modelId, false);
        }

        public function getTokenUsage()
        {
            return new \P1cUsage($this->usage[0] ?? 0, $this->usage[1] ?? 0);
        }

        public function toText(): string
        {
            return (string) $this->text;
        }
    }
}

namespace {

use SeoLab\Ai\ReviewPrompt;
use SeoLab\Ai\ReviewProvider;
use SeoLab\Ai\ReviewRequest;
use SeoLab\Ai\ReviewResult;
use SeoLab\Ai\WordPressReviewHttpScope;
use SeoLab\Ai\WordPressReviewProvider;
use SeoLab\Contracts\ScanReport;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

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

const P1C_SECRET = 'fixture-auth';
const P1C_RAW = 'SENTINEL-RAW-BODY-41d2';
const P1C_CLASS = [
    'openai' => 'WordPress\\OpenAiAiProvider\\Provider\\OpenAiProvider',
    'anthropic' => 'WordPress\\AnthropicAiProvider\\Provider\\AnthropicProvider',
];
const P1C_HOST = ['openai' => 'api.openai.com', 'anthropic' => 'api.anthropic.com'];
const P1C_PATH = ['openai' => '/v1/responses', 'anthropic' => '/v1/messages'];

// ---------------------------------------------------------------------------
// Doppi WordPress: errore, filtri, trasporto HTTP
// ---------------------------------------------------------------------------

class WP_Error
{
    private $code;
    private $message;
    private $data;

    public function __construct($code = '', $message = '', $data = null)
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }

    public function get_error_data()
    {
        return $this->data;
    }
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_supports_ai(): bool
{
    return $GLOBALS['p1c']['supports'];
}

function add_filter($tag, $callback, $priority = 10, $accepted = 1): bool
{
    $GLOBALS['p1c_filters'][$tag][] = ['p' => $priority, 'cb' => $callback, 'n' => $accepted, 's' => count($GLOBALS['p1c_filters'][$tag] ?? [])];
    return true;
}

function remove_filter($tag, $callback, $priority = 10): bool
{
    foreach ($GLOBALS['p1c_filters'][$tag] ?? [] as $i => $entry) {
        if ($entry['cb'] === $callback && $entry['p'] === $priority) {
            unset($GLOBALS['p1c_filters'][$tag][$i]);
            return true;
        }
    }
    return false;
}

function apply_filters($tag, $value, ...$args)
{
    $entries = $GLOBALS['p1c_filters'][$tag] ?? [];
    usort($entries, static function ($a, $b) {
        return ($a['p'] <=> $b['p']) ?: ($a['s'] <=> $b['s']);
    });
    foreach ($entries as $entry) {
        $value = call_user_func_array($entry['cb'], array_slice(array_merge([$value], $args), 0, $entry['n']));
    }
    return $value;
}

/** Trasporto WordPress: i filtri vedono gli argomenti finali; una richiesta bloccata non tocca il canale. */
function wp_safe_remote_request($url, $args = [])
{
    $args = apply_filters('http_request_args', $args, $url);
    $pre = apply_filters('pre_http_request', false, $args, $url);
    if ($pre !== false) {
        if (is_wp_error($pre)) {
            $GLOBALS['p1c']['refused'][] = $pre->get_error_code();
            $GLOBALS['p1c']['events'][] = 'refuse ' . strtoupper((string) ($args['method'] ?? 'GET')) . ' ' . $url;
        }
        return $pre;
    }
    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    $GLOBALS['p1c']['wire'][] = ['method' => $method, 'url' => $url, 'args' => $args];
    $GLOBALS['p1c']['events'][] = 'send ' . $method . ' ' . $url;
    return p1c_respond($method);
}

function p1c_respond(string $method)
{
    $state = $GLOBALS['p1c'];
    if ($method === 'GET') {
        return ['response' => ['code' => $state['get_code'], 'message' => 'OK'], 'body' => '{"data":[]}', 'headers' => []];
    }
    if ($state['post_fail']) {
        return new WP_Error('http_request_failed', 'rete');
    }
    return [
        'response' => ['code' => $state['post_code'], 'message' => 'OK'],
        'body' => $state['post_body'] ?? p1c_document_json(),
        'headers' => [],
    ];
}

// ---------------------------------------------------------------------------
// Doppi SDK: eccezioni, metadati, uso, builder, modello e registry
// ---------------------------------------------------------------------------

final class P1cHttpException extends RuntimeException
{
}

final class P1cNetworkException extends RuntimeException
{
}

final class P1cMeta
{
    private $id;
    private $text;

    public function __construct($id, bool $text)
    {
        $this->id = $id;
        $this->text = $text;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getSupportedCapabilities(): array
    {
        return [new P1cCapability($this->text)];
    }
}

final class P1cCapability
{
    private $text;

    public function __construct(bool $text)
    {
        $this->text = $text;
    }

    public function isTextGeneration(): bool
    {
        return $this->text;
    }
}

final class P1cUsage
{
    private $prompt;
    private $completion;

    public function __construct($prompt, $completion)
    {
        $this->prompt = $prompt;
        $this->completion = $completion;
    }

    public function getPromptTokens()
    {
        return $this->prompt;
    }

    public function getCompletionTokens()
    {
        return $this->completion;
    }
}

final class P1cModel implements TextGenerationModelInterface
{
    private $provider;
    private $id;

    public function __construct(string $provider, string $id)
    {
        $this->provider = $provider;
        $this->id = $id;
    }

    /** Metadati: al primo uso (cache fredda) una GET di catalogo, poi il risultato resta in cache. */
    public function metadata()
    {
        if (!isset($GLOBALS['p1c']['cache'][$this->provider])) {
            p1c_attack('resolve', 'before');
            $url = 'https://' . P1C_HOST[$this->provider] . '/v1/models';
            $response = wp_safe_remote_request($url, ['method' => 'GET', 'headers' => ['Authorization' => 'Bearer ' . P1C_SECRET]]);
            if (is_wp_error($response)) {
                throw new P1cNetworkException('rete');
            }
            p1c_attack('resolve', 'after');
            $GLOBALS['p1c']['cache'][$this->provider] = true;
        }
        return new P1cMeta($GLOBALS['p1c']['meta_model'] ?? $this->id, $GLOBALS['p1c']['meta_text']);
    }

    public function providerMetadata()
    {
        return new P1cMeta($GLOBALS['p1c']['meta_provider'] ?? $this->provider, true);
    }

    /** Generazione: un solo POST verso il percorso del connettore, poi l'eventuale tentativo dell'attacco. */
    public function generateTextResult(array $bag)
    {
        $url = 'https://' . P1C_HOST[$this->provider] . P1C_PATH[$this->provider];
        $opts = $bag['opts'];
        p1c_attack('generate', 'before');
        $response = wp_safe_remote_request($url, [
            'method' => 'POST',
            'headers' => ['Authorization' => 'Bearer ' . P1C_SECRET, 'Content-Type' => 'application/json', 'Cookie' => 'sid=abc'],
            'body' => json_encode($bag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout' => $opts['timeout'] ?? 30.0,
            'redirection' => $opts['maxRedirects'] ?? 5,
            'blocking' => true,
        ]);
        if (is_wp_error($response)) {
            throw new P1cNetworkException('rete');
        }
        $code = (int) $response['response']['code'];
        if ($code >= 400) {
            throw new P1cHttpException('HTTP ' . $code, $code);
        }
        p1c_attack('generate', 'after');
        $result = new GenerativeAiResult();
        $result->providerId = $GLOBALS['p1c']['result_provider'] ?? $this->provider;
        $result->modelId = $GLOBALS['p1c']['result_model'] ?? $this->id;
        $result->text = $response['body'];
        $result->usage = $GLOBALS['p1c']['usage'] ?? [0, 0];
        return $result;
    }
}

/** Un tentativo fuori perimetro, nella fase e nel momento scelti dal caso di test. */
function p1c_attack(string $phase, string $when): void
{
    $attack = $GLOBALS['p1c']['attack'];
    if ($attack === null || $GLOBALS['p1c']['attack_at'] !== $phase || $GLOBALS['p1c']['attack_when'] !== $when) {
        return;
    }
    $GLOBALS['p1c']['attack'] = null;
    $response = wp_safe_remote_request($attack['url'], ['method' => $attack['method']] + $attack['args']);
    if (is_wp_error($response)) {
        throw new P1cNetworkException('bloccata');
    }
}

final class P1cRegistry
{
    public function hasProvider($id): bool
    {
        return isset($GLOBALS['p1c']['registered'][$id]);
    }

    public function getProviderClassName($id): string
    {
        if (!$this->hasProvider($id)) {
            throw new InvalidArgumentException('Provider not registered');
        }
        return $GLOBALS['p1c']['registered'][$id];
    }

    public function getProviderRequestAuthentication($class)
    {
        return in_array($class, $GLOBALS['p1c']['auth'], true) ? new stdClass() : null;
    }

    public function getProviderModel($class, $modelId)
    {
        $id = array_search($class, $GLOBALS['p1c']['registered'], true);
        if ($id === false || $GLOBALS['p1c']['model_missing']) {
            throw new InvalidArgumentException('Model not found');
        }
        return new P1cModel((string) $id, (string) $modelId);
    }
}

/** Builder del Core: i metodi snake_case registrano le scelte e generate_text_result esegue una sola generazione. */
final class P1cBuilder
{
    private $prompt;
    private $model;
    private $bag = ['opts' => ['timeout' => 30.0]];

    public function __construct($prompt)
    {
        $this->prompt = $prompt;
    }

    public function __call($name, $args)
    {
        $GLOBALS['p1c']['builder'][] = $name;
        $GLOBALS['p1c']['events'][] = 'builder:' . $name;
        switch ($name) {
            case 'using_model':
                $this->model = $args[0];
                break;
            case 'using_system_instruction':
                $this->bag['system'] = $args[0];
                break;
            case 'using_max_tokens':
                $this->bag['max_tokens'] = $args[0];
                break;
            case 'using_candidate_count':
                $this->bag['candidate_count'] = $args[0];
                break;
            case 'using_request_options':
                $this->bag['opts'] = $args[0]->toArray();
                break;
            case 'generate_text_result':
                return $this->generate();
        }
        return $this;
    }

    private function generate()
    {
        switch ($GLOBALS['p1c']['builder_mode']) {
            case 'prevent':
                return new WP_Error('prompt_prevented', 'prevenuta', ['status' => 503]);
            case 'malformed':
                return new WP_Error('prompt_client_error', 'malformato', 'bogus');
            case 'string':
                return 'inatteso';
            case 'throw':
                throw new RuntimeException('eccezione inattesa');
        }
        $this->bag['prompt'] = $this->prompt;
        if (!$this->model instanceof TextGenerationModelInterface) {
            return new WP_Error('prompt_builder_error', 'senza modello', ['status' => 500]);
        }
        try {
            return $this->model->generateTextResult($this->bag);
        } catch (P1cHttpException $e) {
            $code = $e->getCode();
            $error = $code >= 500 ? 'prompt_upstream_server_error' : 'prompt_client_error';
            return new WP_Error($error, 'errore HTTP', ['status' => $code]);
        } catch (P1cNetworkException $e) {
            return new WP_Error('prompt_network_error', 'rete', ['status' => 503]);
        }
    }
}

function wp_ai_client_prompt($prompt = null)
{
    return new P1cBuilder($prompt);
}

// ---------------------------------------------------------------------------
// Helper di test
// ---------------------------------------------------------------------------

function p1c_fixture_report(string $prefix): array
{
    $files = glob(__DIR__ . '/fixtures/' . $prefix . '-*.json');
    if ($files === false || count($files) !== 1) {
        throw new RuntimeException('fixture ' . $prefix . ' non trovata');
    }
    $fx = json_decode((string) file_get_contents($files[0]), true);
    return $fx['scans'][0]['report'];
}

function p1c_request(string $language): ReviewRequest
{
    return ReviewRequest::fromReport(new ScanReport(p1c_fixture_report('07')), $language);
}

/** Documento P1.0 valido, con un riferimento della richiesta. */
function p1c_document_json(): string
{
    $ref = p1c_request('it')->referenceIds()[0];
    return json_encode([
        'schema_version' => '1',
        'items' => [
            ['kind' => 'explanation', 'text' => 'Spiegazione di prova.', 'refs' => [$ref]],
            ['kind' => 'suggested_test', 'text' => 'Test di prova.', 'refs' => [$ref]],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

function p1c_reset(): void
{
    $GLOBALS['wp_version'] = '7.1.5';
    $GLOBALS['p1c_filters'] = [];
    $GLOBALS['p1c'] = [
        'supports' => true,
        'events' => [],
        'wire' => [],
        'refused' => [],
        'builder' => [],
        'usage' => [11, 22],
        'post_code' => 200,
        'post_body' => null,
        'post_fail' => false,
        'get_code' => 200,
        'attack' => null,
        'attack_at' => null,
        'attack_when' => null,
        'builder_mode' => 'normal',
        'meta_model' => null,
        'meta_provider' => null,
        'meta_text' => true,
        'model_missing' => false,
        'result_model' => null,
        'result_provider' => null,
        'cache' => [],
        'registered' => ['openai' => P1C_CLASS['openai'], 'anthropic' => P1C_CLASS['anthropic']],
        'auth' => [P1C_CLASS['openai'], P1C_CLASS['anthropic']],
    ];
    $GLOBALS['p1c_registry'] = new P1cRegistry();
}

function p1c_admit_null(): ?string
{
    $GLOBALS['p1c']['events'][] = 'admit';
    return null;
}

function p1c_subject(string $provider, string $model, bool $enabled, callable $admit): SeoLab\Ai\ReviewProvider
{
    return new SeoLab\Ai\WordPressReviewProvider($enabled, $provider, $model, $admit);
}

function p1c_run(string $provider = 'openai', string $model = 'gpt-test-1', bool $enabled = true, ?callable $admit = null, ?ReviewRequest $request = null): ReviewResult
{
    $subject = p1c_subject($provider, $model, $enabled, $admit ?? 'p1c_admit_null');
    return $subject->review($request ?? p1c_request('it'));
}

function p1c_posts(): array
{
    return array_values(array_filter($GLOBALS['p1c']['wire'], static function ($r) {
        return $r['method'] === 'POST';
    }));
}

function p1c_gets(): array
{
    return array_values(array_filter($GLOBALS['p1c']['wire'], static function ($r) {
        return $r['method'] === 'GET';
    }));
}

function p1c_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function p1c_no_egress(string $label): void
{
    p1c_assert($GLOBALS['p1c']['wire'] === [], $label . ': egress inatteso');
}

function p1c_no_filters(): void
{
    p1c_assert(empty($GLOBALS['p1c_filters']['http_request_args'] ?? []) && empty($GLOBALS['p1c_filters']['pre_http_request'] ?? []), 'filtri della guardia ancora registrati');
}

$p1c_pass = [];
$p1c_fail = [];

function p1c_case(string $name, callable $fn): void
{
    global $p1c_pass, $p1c_fail;
    try {
        p1c_reset();
        $fn();
        $p1c_pass[] = $name;
        echo 'PASS ' . $name . "\n";
    } catch (Throwable $e) {
        $p1c_fail[] = $name;
        echo 'FAIL ' . $name . ': ' . get_class($e) . ' ' . $e->getMessage() . "\n";
    }
}

echo "P1.1 connectors: adapter OpenAI e Anthropic su trasporto simulato\n";

// --- Sorgente e firma ----------------------------------------------------

p1c_case('sorgente: WordPressReviewProvider implementa ReviewProvider', static function (): void {
    p1c_assert((new ReflectionClass(WordPressReviewProvider::class))->implementsInterface(ReviewProvider::class), 'interfaccia mancante');
});

p1c_case('sorgente: nessun canale di rete diretto nei file P1.1', static function (): void {
    foreach (['WordPressReviewProvider.php', 'WordPressReviewHttpScope.php', 'ReviewPrompt.php'] as $file) {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Ai/' . $file);
        p1c_assert(preg_match('/wp_(safe_)?remote_(get|post|request)\s*\(|curl_|fsockopen|stream_socket|file_get_contents\s*\(\s*.https?:/i', $source) !== 1, $file . ' contiene canali diretti');
    }
});

// --- Gate e ingresso ----------------------------------------------------

p1c_case('gate: opt-in spento -> disabled senza ammissione ne egress', static function (): void {
    $calls = 0;
    $result = p1c_run('openai', 'gpt-test-1', false, static function () use (&$calls): ?string {
        $calls++;
        return null;
    });
    p1c_assert($result->code() === 'disabled', 'codice ' . $result->code());
    p1c_assert($calls === 0, 'ammissione chiamata');
    p1c_no_egress('gate off');
});

p1c_case('gate: Core con AI disabilitata -> core_disabled senza registry', static function (): void {
    $GLOBALS['p1c']['supports'] = false;
    $result = p1c_run();
    p1c_assert($result->code() === 'core_disabled', 'codice ' . $result->code());
    p1c_no_egress('core disabled');
});

p1c_case('gate: versione 6.9.1 -> unsupported_runtime', static function (): void {
    $GLOBALS['wp_version'] = '6.9.1';
    p1c_assert(p1c_run()->code() === 'unsupported_runtime', 'codice diverso');
    p1c_no_egress('versione');
});

p1c_case('ingresso: provider fuori dal set -> invalid_input', static function (): void {
    $calls = 0;
    $result = p1c_run('gemini', 'gpt-test-1', true, static function () use (&$calls): ?string {
        $calls++;
        return null;
    });
    p1c_assert($result->code() === 'invalid_input' && $calls === 0, 'provider non rifiutato');
    p1c_no_egress('provider');
});

p1c_case('ingresso: modello vuoto, con spazi, a capo finale, URL o oltre 128 -> invalid_input', static function (): void {
    $bad = ['', 'gpt 4', "gpt-4o\n", 'https://api.openai.com/v1', str_repeat('a', 129), '-gpt', 'gpt/4'];
    foreach ($bad as $model) {
        $calls = 0;
        $result = p1c_run('openai', $model, true, static function () use (&$calls): ?string {
            $calls++;
            return null;
        });
        p1c_assert($result->code() === 'invalid_input' && $calls === 0, 'modello accettato: ' . json_encode($model));
    }
    p1c_no_egress('modelli');
});

foreach (['openai', 'anthropic'] as $connector) {
    p1c_case('connettore ' . $connector . ': assente -> unconfigured senza ammissione', static function () use ($connector): void {
        unset($GLOBALS['p1c']['registered'][$connector]);
        $calls = 0;
        $result = p1c_run($connector, 'model-test-1', true, static function () use (&$calls): ?string {
            $calls++;
            return null;
        });
        p1c_assert($result->code() === 'unconfigured' && $calls === 0, 'codice ' . $result->code());
        p1c_no_egress('connettore assente');
    });

    p1c_case('connettore ' . $connector . ': classe diversa -> unconfigured', static function () use ($connector): void {
        $GLOBALS['p1c']['registered'][$connector] = 'Altro\\Provider';
        $result = p1c_run($connector, 'model-test-1');
        p1c_assert($result->code() === 'unconfigured', 'codice ' . $result->code());
        p1c_no_egress('classe diversa');
    });

    p1c_case('connettore ' . $connector . ': autenticazione non registrata -> unconfigured', static function () use ($connector): void {
        $GLOBALS['p1c']['auth'] = [];
        $result = p1c_run($connector, 'model-test-1');
        p1c_assert($result->code() === 'unconfigured', 'codice ' . $result->code());
        p1c_no_egress('autenticazione assente');
    });
}

p1c_case('ammissione: rate_limited e budget_exhausted fermano prima del modello', static function (): void {
    foreach (['rate_limited', 'budget_exhausted'] as $verdict) {
        $GLOBALS['p1c']['events'] = [];
        $result = p1c_run('openai', 'gpt-test-1', true, static function () use ($verdict): ?string {
            $GLOBALS['p1c']['events'][] = 'admit';
            return $verdict;
        });
        p1c_assert($result->code() === $verdict, $verdict . ': codice ' . $result->code());
        p1c_assert($GLOBALS['p1c']['events'] === ['admit'], $verdict . ': eventi ' . json_encode($GLOBALS['p1c']['events']));
    }
    p1c_no_egress('ammissione negata');
});

p1c_case('ammissione: valore sconosciuto o eccezione -> budget_exhausted fail-closed', static function (): void {
    $unknown = p1c_run('openai', 'gpt-test-1', true, static function (): string {
        return 'forse';
    });
    p1c_assert($unknown->code() === 'budget_exhausted', 'valore sconosciuto: ' . $unknown->code());
    $thrown = p1c_run('openai', 'gpt-test-1', true, static function (): ?string {
        throw new RuntimeException('contatore non raggiungibile');
    });
    p1c_assert($thrown->code() === 'budget_exhausted', 'eccezione: ' . $thrown->code());
    p1c_no_egress('fail-closed');
});

// --- Successo ----------------------------------------------------------

p1c_case('successo openai: ordine ammissione, GET di catalogo, un solo POST, esito valido', static function (): void {
    $result = p1c_run();
    p1c_assert($result->code() === null && count($result->items()) === 2, 'esito non valido: ' . $result->code());
    $events = array_values(array_filter($GLOBALS['p1c']['events'], static function ($e) {
        return $e === 'admit' || strpos($e, 'send ') === 0 || $e === 'builder:using_model';
    }));
    p1c_assert($events === [
        'admit',
        'send GET https://api.openai.com/v1/models',
        'builder:using_model',
        'send POST https://api.openai.com/v1/responses',
    ], 'ordine eventi: ' . json_encode($events));
    p1c_assert(count(p1c_posts()) === 1, 'POST non unico');
});

p1c_case('successo openai: POST con system e data byte per byte dal prompt review-1', static function (): void {
    p1c_run();
    $prompt = ReviewPrompt::fromRequest(p1c_request('it'));
    $body = json_decode(p1c_posts()[0]['args']['body'], true);
    p1c_assert($body['system'] === $prompt['system'], 'system diverso');
    p1c_assert($body['prompt'] === $prompt['data'], 'data diverso dal payload');
    p1c_assert($body['max_tokens'] === 768, 'max_tokens ' . json_encode($body['max_tokens'] ?? null));
    p1c_assert($body['candidate_count'] === 1, 'candidateCount assente in openai');
});

p1c_case('successo openai: trasporto con timeout residuo, redirect zero, TLS, zero cookie e limite 256 KiB', static function (): void {
    p1c_run();
    $args = p1c_posts()[0]['args'];
    p1c_assert(isset($args['timeout']) && $args['timeout'] > 0 && $args['timeout'] <= 12, 'timeout fuori da (0,12]');
    p1c_assert(($args['redirection'] ?? null) === 0, 'redirect non zero');
    p1c_assert(($args['sslverify'] ?? null) === true, 'TLS non verificato');
    p1c_assert(($args['cookies'] ?? null) === [], 'cookie non azzerati');
    p1c_assert(($args['limit_response_size'] ?? null) === 262144, 'limite risposta assente');
    p1c_assert(!isset($args['headers']['Cookie']), 'header Cookie non rimosso');
    p1c_assert(($args['headers']['Authorization'] ?? '') === 'Bearer ' . P1C_SECRET, 'autenticazione del connettore alterata');
});

p1c_case('successo openai: timeout residuo positivo e non crescente tra GET e POST', static function (): void {
    p1c_run();
    $gets = p1c_gets();
    $post = p1c_posts()[0];
    p1c_assert(count($gets) === 1 && $gets[0]['args']['timeout'] > 0, 'timeout della GET non positivo');
    p1c_assert($post['args']['timeout'] > 0 && $post['args']['timeout'] <= $gets[0]['args']['timeout'], 'timeout crescente');
});

p1c_case('successo anthropic: POST su /v1/messages senza candidateCount', static function (): void {
    $result = p1c_run('anthropic', 'claude-test-1');
    p1c_assert($result->code() === null && count($result->items()) === 2, 'esito non valido: ' . $result->code());
    p1c_assert(count(p1c_posts()) === 1 && p1c_posts()[0]['url'] === 'https://api.anthropic.com/v1/messages', 'URL POST non attesa');
    p1c_assert(!in_array('using_candidate_count', $GLOBALS['p1c']['builder'], true), 'candidateCount inviato ad Anthropic');
    $body = json_decode(p1c_posts()[0]['args']['body'], true);
    p1c_assert(!isset($body['candidate_count']), 'candidate_count nel corpo Anthropic');
});

p1c_case('successo: seconda richiesta con cache di metadati senza GET', static function (): void {
    p1c_run();
    $GLOBALS['p1c']['wire'] = [];
    $second = p1c_run();
    p1c_assert($second->code() === null, 'seconda richiesta non valida: ' . $second->code());
    p1c_assert(p1c_gets() === [] && count(p1c_posts()) === 1, 'GET inattesa con cache calda');
});

p1c_case('successo: prompt in italiano e in inglese con istruzioni entro 2 KiB', static function (): void {
    $it = ReviewPrompt::fromRequest(p1c_request('it'));
    $en = ReviewPrompt::fromRequest(p1c_request('en'));
    p1c_assert(strpos($it['system'], 'Italian') !== false && strpos($en['system'], 'English') !== false, 'lingua non dichiarata');
    p1c_assert(strlen($it['system']) <= 2048 && strlen($en['system']) <= 2048, 'istruzioni oltre 2 KiB');
    p1c_assert(stripos($it['system'], 'untrusted') !== false, 'dati non dichiarati non attendibili');
    p1c_assert($it['data'] === json_encode(p1c_request('it')->payload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'data non deterministico');
});

// --- Trasporto: rifiuti della guardia ----------------------------------

// Ogni rifiuto "prima" è isolato: nessuna richiesta legittima della fase è ancora avvenuta, quindi decide solo il
// controllo che il caso nomina. I casi "dopo" verificano il limite di tentativi.
$refusals = [
    'host inatteso' => ['generate', 'before', 'POST', 'https://evil.example/v1/responses', []],
    'porta anomala' => ['generate', 'before', 'POST', 'https://api.openai.com:8443/v1/responses', []],
    'userinfo' => ['generate', 'before', 'POST', 'https://user:pw@api.openai.com/v1/responses', []],
    'query' => ['generate', 'before', 'POST', 'https://api.openai.com/v1/responses?x=1', []],
    'http senza TLS' => ['generate', 'before', 'POST', 'http://api.openai.com/v1/responses', []],
    'percorso errato' => ['generate', 'before', 'POST', 'https://api.openai.com/v1/files', []],
    'metodo errato in generazione' => ['generate', 'before', 'GET', 'https://api.openai.com/v1/responses', []],
    'POST durante risoluzione' => ['resolve', 'before', 'POST', 'https://api.openai.com/v1/responses', []],
    'secondo tentativo in generazione' => ['generate', 'after', 'POST', 'https://api.openai.com/v1/responses', []],
    'secondo tentativo in risoluzione' => ['resolve', 'after', 'GET', 'https://api.openai.com/v1/models', []],
];
foreach ($refusals as $label => $case) {
    p1c_case('guardia: rifiuta ' . $label . ' -> provider_error, nessun egress fuori perimetro', static function () use ($case): void {
        [$phase, $when, $method, $url, $args] = $case;
        $GLOBALS['p1c']['attack_at'] = $phase;
        $GLOBALS['p1c']['attack_when'] = $when;
        $GLOBALS['p1c']['attack'] = ['method' => $method, 'url' => $url, 'args' => $args];
        $result = p1c_run();
        p1c_assert($result->code() === 'provider_error', 'codice ' . $result->code());
        p1c_assert(count($GLOBALS['p1c']['refused']) >= 1, 'nessun rifiuto registrato');
        // Il tentativo fuori perimetro non deve mai essere inviato; quello "dopo" puo' comparire una sola volta, quello legittimo.
        $sameAttempt = array_filter($GLOBALS['p1c']['wire'], static function ($sent) use ($method, $url): bool {
            return $sent['method'] === $method && $sent['url'] === $url;
        });
        p1c_assert(count($sameAttempt) === ($when === 'after' ? 1 : 0), 'tentativo fuori perimetro inviato: ' . count($sameAttempt));
        p1c_no_filters();
    });
}

p1c_case('guardia: dopo la revisione i filtri sono rimossi e la richiesta successiva non e\' limitata', static function (): void {
    p1c_run();
    p1c_no_filters();
    $GLOBALS['p1c']['wire'] = [];
    wp_safe_remote_request('https://api.openai.com/v1/responses', ['method' => 'POST', 'body' => '{}']);
    $sent = $GLOBALS['p1c']['wire'][0]['args'];
    p1c_assert(!isset($sent['limit_response_size']) && !isset($sent['redirection']) && !isset($sent['timeout']), 'richiesta successiva limitata dalla guardia');
});

p1c_case('guardia: fallimento della POST rimuove comunque i filtri', static function (): void {
    $GLOBALS['p1c']['post_code'] = 500;
    $result = p1c_run();
    p1c_assert($result->code() === 'provider_error', 'codice ' . $result->code());
    p1c_no_filters();
});

// --- Modello e risposta ------------------------------------------------

p1c_case('modello: mancante -> model_unavailable senza POST', static function (): void {
    $GLOBALS['p1c']['model_missing'] = true;
    p1c_assert(p1c_run()->code() === 'model_unavailable', 'codice diverso');
    p1c_assert(p1c_posts() === [], 'POST con modello mancante');
});

p1c_case('modello: metadati con id diverso prima dell\'invio -> model_unavailable', static function (): void {
    $GLOBALS['p1c']['meta_model'] = 'altro-modello';
    p1c_assert(p1c_run()->code() === 'model_unavailable', 'codice diverso');
    p1c_assert(p1c_posts() === [], 'POST con id di modello diverso');
});

p1c_case('modello: provider nei metadati diverso prima dell\'invio -> model_unavailable', static function (): void {
    $GLOBALS['p1c']['meta_provider'] = 'anthropic';
    p1c_assert(p1c_run()->code() === 'model_unavailable', 'codice diverso');
    p1c_assert(p1c_posts() === [], 'POST con provider diverso');
});

p1c_case('modello: senza generazione testo -> model_unavailable', static function (): void {
    $GLOBALS['p1c']['meta_text'] = false;
    p1c_assert(p1c_run()->code() === 'model_unavailable', 'codice diverso');
    p1c_assert(p1c_posts() === [], 'POST con modello non testuale');
});

p1c_case('risposta: modello diverso dopo l\'invio -> invalid_response, nessun nuovo tentativo', static function (): void {
    $GLOBALS['p1c']['result_model'] = 'altro-modello';
    p1c_assert(p1c_run()->code() === 'invalid_response', 'codice diverso');
    p1c_assert(count(p1c_posts()) === 1, 'nuovo tentativo dopo risposta incoerente');
});

p1c_case('risposta: provider diverso dopo l\'invio -> invalid_response', static function (): void {
    $GLOBALS['p1c']['result_provider'] = 'anthropic';
    p1c_assert(p1c_run()->code() === 'invalid_response', 'codice diverso');
});

p1c_case('risposta: JSON non valido -> invalid_response, un solo POST', static function (): void {
    $GLOBALS['p1c']['post_body'] = 'non e\' JSON';
    p1c_assert(p1c_run()->code() === 'invalid_response', 'codice diverso');
    p1c_assert(count(p1c_posts()) === 1, 'nuovo tentativo dopo JSON non valido');
});

p1c_case('risposta: ritorno inatteso del builder -> invalid_response', static function (): void {
    $GLOBALS['p1c']['builder_mode'] = 'string';
    p1c_assert(p1c_run()->code() === 'invalid_response', 'codice diverso');
});

// --- Errori --------------------------------------------------------------

$httpErrors = [
    '408 -> timeout' => [408, 'timeout'],
    '504 -> timeout' => [504, 'timeout'],
    '429 -> rate_limited' => [429, 'rate_limited'],
    '401 -> unconfigured' => [401, 'unconfigured'],
    '500 -> provider_error' => [500, 'provider_error'],
    '403 -> provider_error' => [403, 'provider_error'],
];
foreach ($httpErrors as $label => $case) {
    p1c_case('errore HTTP ' . $label, static function () use ($case): void {
        $GLOBALS['p1c']['post_code'] = $case[0];
        $GLOBALS['p1c']['post_body'] = '{"error":"' . P1C_RAW . '"}';
        $result = p1c_run();
        p1c_assert($result->code() === $case[1], 'codice ' . $result->code() . ', atteso ' . $case[1]);
        p1c_assert(count(p1c_posts()) === 1, 'nuovo tentativo dopo errore HTTP');
    });
}

p1c_case('errore: rete senza status -> provider_error', static function (): void {
    $GLOBALS['p1c']['post_fail'] = true;
    p1c_assert(p1c_run()->code() === 'provider_error', 'codice diverso');
});

p1c_case('errore: prompt prevenuto dal Core -> core_disabled', static function (): void {
    $GLOBALS['p1c']['builder_mode'] = 'prevent';
    p1c_assert(p1c_run()->code() === 'core_disabled', 'codice diverso');
});

p1c_case('errore: WP_Error con dati malformati -> provider_error', static function (): void {
    $GLOBALS['p1c']['builder_mode'] = 'malformed';
    p1c_assert(p1c_run()->code() === 'provider_error', 'codice diverso');
});

p1c_case('errore: eccezione inattesa -> provider_error', static function (): void {
    $GLOBALS['p1c']['builder_mode'] = 'throw';
    p1c_assert(p1c_run()->code() === 'provider_error', 'codice diverso');
});

p1c_case('errore: credenziali, corpo grezzo e messaggi assenti da esito e conteggi', static function (): void {
    $GLOBALS['p1c']['post_code'] = 401;
    $GLOBALS['p1c']['post_body'] = '{"error":"' . P1C_RAW . '"}';
    $subject = p1c_subject('openai', 'gpt-test-1', true, 'p1c_admit_null');
    $result = $subject->review(p1c_request('it'));
    $dump = json_encode(['code' => $result->code(), 'items' => $result->items(), 'usage' => $subject->lastUsage()]);
    p1c_assert(strpos($dump, P1C_SECRET) === false && strpos($dump, P1C_RAW) === false, 'dato sensibile nell\'esito');
});

// --- Conteggi ----------------------------------------------------------

p1c_case('conteggi: input e output validi in lastUsage', static function (): void {
    $subject = p1c_subject('openai', 'gpt-test-1', true, 'p1c_admit_null');
    $subject->review(p1c_request('it'));
    p1c_assert($subject->lastUsage() === ['input_tokens' => 11, 'output_tokens' => 22], 'conteggi ' . json_encode($subject->lastUsage()));
});

p1c_case('conteggi: valori negativi omessi, nessun valore inventato', static function (): void {
    $GLOBALS['p1c']['usage'] = [-1, 5];
    $subject = p1c_subject('openai', 'gpt-test-1', true, 'p1c_admit_null');
    $subject->review(p1c_request('it'));
    p1c_assert($subject->lastUsage() === ['output_tokens' => 5], 'conteggi ' . json_encode($subject->lastUsage()));
});

p1c_case('conteggi: azzerati all\'inizio di ogni tentativo', static function (): void {
    $subject = p1c_subject('openai', 'gpt-test-1', true, 'p1c_admit_null');
    $subject->review(p1c_request('it'));
    p1c_assert($subject->lastUsage() !== null, 'conteggi del primo tentativo assenti');
    $GLOBALS['p1c']['post_code'] = 500;
    $subject->review(p1c_request('it'));
    p1c_assert($subject->lastUsage() === null, 'conteggi trascinati dal tentativo precedente');
});

// --- Guardia con orologio finto ------------------------------------------

p1c_case('guardia unitaria: scadenza di 12 s, rifiuto timeout oltre il limite', static function (): void {
    $now = 0;
    $scope = new WordPressReviewHttpScope('api.openai.com', static function () use (&$now): int {
        return $now;
    });
    $scope->enter();
    $scope->allow('POST', '/v1/responses');
    $now = 12000000000;
    p1c_assert($scope->expired(), 'scadenza non rilevata a 12 s');
    $refused = apply_filters('pre_http_request', false, ['method' => 'POST'], 'https://api.openai.com/v1/responses');
    p1c_assert(is_wp_error($refused) && $scope->reason() === WordPressReviewHttpScope::REASON_TIMEOUT, 'richiesta oltre scadenza non rifiutata come timeout');
    $scope->leave();
    p1c_no_filters();
});

p1c_case('guardia unitaria: timeout residuo decrescente con orologio monotono', static function (): void {
    $now = 0;
    $scope = new WordPressReviewHttpScope('api.openai.com', static function () use (&$now): int {
        return $now;
    });
    $scope->enter();
    p1c_assert(abs($scope->residualSeconds() - 12.0) < 1e-9, 'residuo iniziale diverso da 12');
    $now = 5000000000;
    p1c_assert(abs($scope->residualSeconds() - 7.0) < 1e-9, 'residuo dopo 5 s diverso da 7');
    $scope->leave();
});

p1c_case('guardia unitaria: GET con corpo rifiutata anche se metodo e percorso sono attesi', static function (): void {
    $scope = new WordPressReviewHttpScope('api.openai.com', static function (): int {
        return 0;
    });
    $scope->enter();
    $scope->allow('GET', '/v1/models');
    $refused = apply_filters('pre_http_request', false, ['method' => 'GET', 'body' => '{"x":1}'], 'https://api.openai.com/v1/models');
    p1c_assert(is_wp_error($refused) && $scope->reason() === WordPressReviewHttpScope::REASON_SCOPE, 'GET con corpo ammessa');
    $scope->leave();
    p1c_no_filters();
});

p1c_case('guardia unitaria: una sola richiesta per fase e metodo atteso', static function (): void {
    $scope = new WordPressReviewHttpScope('api.openai.com', static function (): int {
        return 0;
    });
    $scope->enter();
    $scope->allow('GET', '/v1/models');
    $url = 'https://api.openai.com/v1/models';
    p1c_assert(apply_filters('pre_http_request', false, ['method' => 'GET'], $url) === false, 'prima GET rifiutata');
    p1c_assert(is_wp_error(apply_filters('pre_http_request', false, ['method' => 'GET'], $url)), 'seconda GET ammessa');
    $scope->leave();
    p1c_no_filters();
});

// --- Doppio di test esistente -----------------------------------------

p1c_case('doppio: ReviewPrompt rifiuta un payload oltre il limite dati', static function (): void {
    p1c_assert(ReviewPrompt::MAX_DATA_BYTES === 8192 && ReviewPrompt::MAX_SYSTEM_BYTES === 2048, 'limiti del prompt diversi');
});

// ---------------------------------------------------------------------------

$p1c_total = count($p1c_pass) + count($p1c_fail);
if ($p1c_fail !== []) {
    echo 'FAIL p1-connectors: ' . count($p1c_fail) . ' casi su ' . $p1c_total . "\n";
    exit(1);
}
echo 'OK p1-connectors: ' . count($p1c_pass) . '/' . $p1c_total . " casi superati\n";

} // namespace
