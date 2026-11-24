<?php

declare(strict_types=1);

/*
 * Verificatore P1.2 di SEO Lab: revisione AI privata su un rapporto archiviato. Uso: php tests/p1-admin.php.
 * Il controller, la riserva e la vista girano davvero; sono doppi solo le dipendenze WordPress (utente, nonce,
 * opzioni, archivio in memoria, registro in memoria, provider scriptato). Il registry Core per isConfiguredLocally
 * e' un doppio. La prova sulla WordPress reale sta in tests/s4-wordpress.sh --p1-ai, fuori da questo file.
 * Ogni caso e' isolato: un errore e' una riga FAIL con il nome.
 */

namespace WordPress\AiClient {
    final class AiClient
    {
        public static function defaultRegistry()
        {
            $GLOBALS['p1a']['lookups']++;
            return $GLOBALS['p1a']['registry'];
        }
    }
}

namespace {

use SeoLab\Admin\AiReviewActions;
use SeoLab\Admin\AiReviewView;
use SeoLab\Ai\ReviewConfirmation;
use SeoLab\Ai\ReviewPrompt;
use SeoLab\Ai\ReviewProvider;
use SeoLab\Ai\ReviewRequest;
use SeoLab\Ai\ReviewResult;
use SeoLab\Ai\WordPressReviewProvider;
use SeoLab\Contracts\ReportStorage;
use SeoLab\Contracts\ScanReport;
use SeoLab\Storage\AiLedgerStore;
use SeoLab\Storage\WordPressAiLedger;

spl_autoload_register(static function (string $class): void {
    $roots = [
        'SeoLab\\Admin\\' => 'src/Admin/',
        'SeoLab\\Ai\\' => 'src/Ai/',
        'SeoLab\\Contracts\\' => 'src/Contracts/',
        'SeoLab\\Diagnosis\\' => 'src/Diagnosis/',
        'SeoLab\\Storage\\' => 'src/Storage/',
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

// Funzioni WordPress minime: stesso escaping di esc_html/esc_attr (senza doppia codifica).
function __($text, $domain = null)
{
    return $text;
}
function esc_html__($text, $domain = null)
{
    return $text;
}
function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false);
}
function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false);
}
function esc_url($url)
{
    return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8', false);
}
// Stub per Plugin::aiReviewForm: opzioni del sito, post, nonce e gate del runtime. Letti dai valori di $GLOBALS['p1a'].
function get_option($name, $default = false)
{
    return $GLOBALS['p1a']['opt'][$name] ?? $default;
}
function get_current_blog_id()
{
    return 1;
}
function admin_url($path = '')
{
    return 'https://site.example/wp-admin/' . ltrim((string) $path, '/');
}
function wp_create_nonce($action)
{
    return 'nonce-' . substr(hash('sha256', (string) $action), 0, 12);
}
function get_post($id)
{
    return (object) ['ID' => $id, 'post_status' => 'publish', 'post_type' => 'page', 'post_password' => ''];
}
function get_permalink($id)
{
    return 'https://site.example/?p=' . $id;
}
function wp_supports_ai()
{
    return true;
}
function wp_ai_client_prompt($prompt = null)
{
    throw new RuntimeException('non usato in questa prova');
}

const P1A_UUID_07 = '00000000-0000-4000-8000-000000000711';
const P1A_UUID_10 = '00000000-0000-4000-8000-000000001011';
const P1A_CLASS = 'WordPress\\OpenAiAiProvider\\Provider\\OpenAiProvider';
const P1A_SENTINEL = 'SENTINEL-KEY-p1-admin';
const P1A_TODAY = 1800000000; // istante fisso per i casi; non e' un giorno UTC particolare

$p1a_pass = [];
$p1a_fail = [];

// ---------------------------------------------------------------------------
// Doppi
// ---------------------------------------------------------------------------

final class FakeArchive implements ReportStorage
{
    public $reports = [];
    public $findCalls = 0;

    public function save(ScanReport $report): void
    {
        $this->reports[$report->scanId()] = $report;
    }

    public function find(string $scanId): ?ScanReport
    {
        $this->findCalls++;
        return $this->reports[$scanId] ?? null;
    }

    public function forPost(int $postId): array
    {
        return [];
    }

    public function ensureSchema(): bool
    {
        return true;
    }

    public function prune(int $postId, array $mustKeepScanIds): void
    {
    }
}

/**
 * Registro in memoria con le stesse garanzie della riga DB: inserimento che non sovrascrive, CAS binario.
 * I conflitti forzati simulano un altro scrittore che arriva prima.
 */
final class FakeStore implements AiLedgerStore
{
    public $value = null;
    public $reads = 0;
    public $writes = 0;
    public $readError = false;
    public $forceInsertConflicts = 0;
    public $forceCasConflicts = 0;
    /** @var callable|null eseguito una volta prima del CAS: simula una richiesta concorrente */
    public $beforeCas = null;

    public function read(): ?string
    {
        $this->reads++;
        if ($this->readError) {
            throw new RuntimeException('lettura simulata');
        }
        return $this->value;
    }

    public function insertIgnore(string $value): int
    {
        $this->writes++;
        if ($this->forceInsertConflicts > 0) {
            // Un altro scrittore ha creato la riga un istante prima: l'inserimento non sovrascrive.
            $this->forceInsertConflicts--;
            $this->value = json_encode(['v' => 1, 'rows' => [[999, hash('sha256', 'altro'), P1A_TODAY - 5, P1A_TODAY - 5, 1]]]);
            return 0;
        }
        if ($this->value !== null) {
            return 0;
        }
        $this->value = $value;
        return 1;
    }

    public function compareAndSwap(string $expected, string $value): int
    {
        $this->writes++;
        if ($this->beforeCas !== null) {
            $hook = $this->beforeCas;
            $this->beforeCas = null;
            $hook();
        }
        if ($this->forceCasConflicts > 0) {
            $this->forceCasConflicts--;
            return 0;
        }
        if ($this->value !== $expected) {
            return 0;
        }
        $this->value = $value;
        return 1;
    }
}

/**
 * Provider scriptato: ammissione prima di ogni passo remoto, come WordPressReviewProvider.
 */
final class FakeReviewer implements ReviewProvider
{
    private $env;
    private $provider;
    private $admit;

    public function __construct(P1aEnv $env, string $provider, callable $admit)
    {
        $this->env = $env;
        $this->provider = $provider;
        $this->admit = $admit;
    }

    public function review(ReviewRequest $request): ReviewResult
    {
        // Come WordPressReviewProvider: connettore e autenticazione prima dell'ammissione, senza egress.
        if (empty($this->env->providers[$this->provider])) {
            return ReviewResult::failure('unconfigured');
        }
        if ($this->env->beforeAdmit !== null) {
            ($this->env->beforeAdmit)();
        }
        $verdict = ($this->admit)();
        if ($verdict !== null) {
            return ReviewResult::failure($verdict);
        }
        $this->env->modelCalls++;
        return $this->env->resultFor($request);
    }
}

final class P1aEnv
{
    public $blog = '1';
    public $user = 7;
    public $can = true;
    public $siteOk = true;
    public $optIn = true;
    public $runtime = 'core_available';
    public $now = P1A_TODAY;
    public $eligible = true;
    public $providers = ['openai' => true, 'anthropic' => false];
    public $script = 'ok';
    public $archive;
    public $store;
    public $ledger;
    public $modelCalls = 0;
    public $beforeAdmit = null;
    public $configuredLookups = 0;
    public $factoryCalls = 0;
    public $saved = null;
    private $rand = 0;

    public function __construct()
    {
        $this->archive = new FakeArchive();
        $this->store = new FakeStore();
        $this->ledger = new WordPressAiLedger($this->store);
        $this->archive->save(new ScanReport(p1a_fixture('07')));
        $this->archive->save(new ScanReport(p1a_fixture('10')));
    }

    public function nonce(string $action): string
    {
        return 'n' . substr(hash('sha256', $action . '|' . $this->user), 0, 24);
    }

    public function controller(): AiReviewActions
    {
        $env = $this;
        return new AiReviewActions(
            $this->blog,
            static function () use ($env): bool {
                return $env->siteOk;
            },
            static function () use ($env): bool {
                return $env->can;
            },
            static function () use ($env): int {
                return $env->user;
            },
            static function (string $action, string $value) use ($env): bool {
                return $value === $env->nonce($action);
            },
            static function (string $action) use ($env): string {
                return $env->nonce($action);
            },
            static function () use ($env): int {
                return $env->now;
            },
            static function () use ($env): string {
                return sprintf('%032x', ++$env->rand + 0x1000);
            },
            static function () use ($env): bool {
                return $env->optIn;
            },
            static function (string $value) use ($env): bool {
                $env->saved = $value;
                return true;
            },
            static function (bool $enabled) use ($env): string {
                return $enabled ? $env->runtime : 'disabled';
            },
            $this->archive,
            static function (int $postId) use ($env): bool {
                return $env->eligible;
            },
            static function (string $provider) use ($env): bool {
                $env->configuredLookups++;
                return !empty($env->providers[$provider]);
            },
            $this->ledger,
            static function (bool $enabled, string $provider, string $model, callable $admit) use ($env): ReviewProvider {
                $env->factoryCalls++;
                return new FakeReviewer($env, $provider, $admit);
            },
            'https://site.example/wp-admin/admin-post.php',
            'https://site.example/wp-admin/options-general.php?page=seo-lab-ai',
            'https://site.example/wp-admin/tools.php?page=seo-lab'
        );
    }

    /**
     * Risposta del modello scriptata. Le ref arrivano dalla richiesta reale, quindi sono valide per costruzione.
     */
    public function resultFor(ReviewRequest $request): ReviewResult
    {
        $refs = $request->referenceIds();
        $first = $refs[0];
        $last = $refs[count($refs) - 1];
        switch ($this->script) {
            case 'provider_error':
                return ReviewResult::failure('provider_error');
            case 'output_truncated':
                return ReviewResult::failure('output_truncated');
            case 'unknown_ref':
                return ReviewResult::fromJson(p1a_json([['explanation', 'Spiegazione.', ['NOPE:9']], ['suggested_test', 'Test.', [$first]]]), $request);
            case 'bidi_only':
                return ReviewResult::fromJson(p1a_json([['explanation', "\u{202E}\u{200F}", [$first]], ['suggested_test', 'Test.', [$first]]]), $request);
            case 'separators':
                return ReviewResult::fromJson(p1a_json([['explanation', "Uno\u{2028}due\u{00A0}tre", [$first]], ['suggested_test', 'Test.', [$first]]]), $request);
            case 'escape':
                return ReviewResult::fromJson(p1a_json([['explanation', '<script>alert(1)</script> &amp;', [$first]], ['suggested_test', 'Test.', [$first]]]), $request);
            case 'entity':
                return ReviewResult::fromJson(p1a_json([['explanation', 'Ordine &#x202E;&lrm; inverso', [$first]], ['suggested_test', 'Test.', [$first]]]), $request);
            default:
                return ReviewResult::fromJson(p1a_json([
                    ['explanation', 'Spiegazione prudente del problema.', [$first]],
                    ['hypothesis', 'Ipotesi: possibile conflitto di dati.', [$last]],
                    ['suggested_test', 'Verifica il dato atteso nel sito.', [$first]],
                ]), $request);
        }
    }
}

// ---------------------------------------------------------------------------
// Utilita'
// ---------------------------------------------------------------------------

function p1a_fixture(string $prefix): array
{
    $files = glob(__DIR__ . '/fixtures/' . $prefix . '-*.json');
    if ($files === false || count($files) !== 1) {
        throw new RuntimeException('fixture ' . $prefix . ' non trovata');
    }
    $fx = json_decode((string) file_get_contents($files[0]), true);
    return $fx['scans'][0]['report'];
}

/**
 * @param array<int, array{0: string, 1: string, 2: string[]}> $items
 */
function p1a_json(array $items): string
{
    $out = [];
    foreach ($items as [$kind, $text, $refs]) {
        $out[] = ['kind' => $kind, 'text' => $text, 'refs' => $refs];
    }
    return (string) json_encode(['schema_version' => '1', 'items' => $out], JSON_UNESCAPED_UNICODE);
}

function p1a_preview_request(P1aEnv $env, string $reportId, string $provider = 'openai', string $model = 'gpt-test-1', string $language = 'it'): array
{
    return [
        'report' => $reportId,
        'provider' => $provider,
        'model' => $model,
        'language' => $language,
        'seo_lab_nonce' => $env->nonce(ReviewConfirmation::phaseAction('preview', $env->blog, $reportId)),
    ];
}

/**
 * Valore del campo nascosto nell'HTML restituito. Null se assente.
 */
function p1a_field(string $html, string $name): ?string
{
    if (preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m) !== 1) {
        return null;
    }
    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

/**
 * Richiesta di conferma costruita dai campi reali del modulo di anteprima.
 */
function p1a_confirm_request(string $preview): array
{
    $names = ['report', 'provider', 'model', 'language', 'issued_at', 'confirmation_id', 'seo_lab_nonce', 'seo_lab_ctx_nonce'];
    $request = [];
    foreach ($names as $name) {
        $request[$name] = p1a_field($preview, $name);
    }
    return $request;
}

function p1a_status(array $response): int
{
    return (int) $response['status'];
}

function p1a_case(string $name, callable $fn): void
{
    global $p1a_pass, $p1a_fail;
    $GLOBALS['p1a'] = ['lookups' => 0, 'registry' => null];
    try {
        $fn();
        $p1a_pass[] = $name;
        echo 'PASS ' . $name . "\n";
    } catch (Throwable $e) {
        $p1a_fail[] = $name;
        echo 'FAIL ' . $name . ': ' . get_class($e) . ' ' . $e->getMessage() . "\n";
    }
}

function p1a_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

echo "P1.2 admin: revisione AI privata su rapporto archiviato\n";

// ---------------------------------------------------------------------------
// Gate: metodo, contesto, capability, nonce, input
// ---------------------------------------------------------------------------

p1a_case('gate: GET su anteprima, conferma e impostazioni -> 405 senza archivio', static function (): void {
    $env = new P1aEnv();
    $c = $env->controller();
    p1a_assert(p1a_status($c->preview('GET', [])) === 405, 'anteprima GET non 405');
    p1a_assert(p1a_status($c->confirm('GET', [])) === 405, 'conferma GET non 405');
    p1a_assert(p1a_status($c->settings('GET', [])) === 405, 'impostazioni GET non 405');
    p1a_assert($env->archive->findCalls === 0, 'archivio letto prima dei controlli di fase');
});

p1a_case('gate: POST anonimo o senza manage_options -> 403 prima dell\'archivio', static function (): void {
    $env = new P1aEnv();
    $env->can = false;
    $request = p1a_preview_request($env, P1A_UUID_07);
    p1a_assert(p1a_status($env->controller()->preview('POST', $request)) === 403, 'capability insufficiente non 403');
    $env->can = true;
    $env->user = 0;
    p1a_assert(p1a_status($env->controller()->preview('POST', $request)) === 403, 'utente assente non 403');
    p1a_assert($env->archive->findCalls === 0, 'archivio letto con permesso negato');
});

p1a_case('gate: contesto di rete o blog commutato -> 403', static function (): void {
    $env = new P1aEnv();
    $env->siteOk = false;
    p1a_assert(p1a_status($env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07))) === 403, 'contesto non ammesso non 403');
    p1a_assert(p1a_status($env->controller()->settings('POST', ['seolab_ai_enabled' => '1'])) === 403, 'impostazioni in contesto non ammesso non 403');
    p1a_assert($env->saved === null, 'opzione salvata in contesto non ammesso');
});

p1a_case('gate: nonce errato, mancante o di altra sessione -> 403 senza archivio', static function (): void {
    $env = new P1aEnv();
    $request = p1a_preview_request($env, P1A_UUID_07);
    $request['seo_lab_nonce'] = 'nessuno';
    p1a_assert(p1a_status($env->controller()->preview('POST', $request)) === 403, 'nonce errato non 403');
    unset($request['seo_lab_nonce']);
    p1a_assert(p1a_status($env->controller()->preview('POST', $request)) === 403, 'nonce mancante non 403');
    $request = p1a_preview_request($env, P1A_UUID_07);
    $env->user = 8;
    p1a_assert(p1a_status($env->controller()->preview('POST', $request)) === 403, 'nonce di altro utente non 403');
    p1a_assert($env->archive->findCalls === 0, 'archivio letto con nonce errato');
});

p1a_case('gate: nonce di un sito non si riusa su un altro sito -> 403', static function (): void {
    $env = new P1aEnv();
    $request = p1a_preview_request($env, P1A_UUID_07);
    $env->blog = '2';
    p1a_assert(p1a_status($env->controller()->preview('POST', $request)) === 403, 'nonce del sito 1 accettato sul sito 2');
});

p1a_case('gate: input spuri, array o fuori dal set -> 400', static function (): void {
    $env = new P1aEnv();
    $c = $env->controller();
    $base = p1a_preview_request($env, P1A_UUID_07);
    $cases = [
        'report array' => array_merge($base, ['report' => [P1A_UUID_07]]),
        'report non UUID' => array_merge($base, ['report' => 'x']),
        'provider fuori set' => array_merge($base, ['provider' => 'gemini']),
        'modello con spazi' => array_merge($base, ['model' => 'gpt test']),
        'modello oltre 128' => array_merge($base, ['model' => str_repeat('a', 129)]),
        'lingua fuori set' => array_merge($base, ['language' => 'fr']),
    ];
    foreach ($cases as $label => $request) {
        p1a_assert(p1a_status($c->preview('POST', $request)) === 400, $label . ' non 400');
    }
    p1a_assert($env->archive->findCalls === 0, 'archivio letto con input non valido');
});

p1a_case('gate: impostazioni, valore fuori da 0 e 1 -> 400 senza modifica', static function (): void {
    $env = new P1aEnv();
    $request = ['seo_lab_nonce' => $env->nonce('seo_lab_ai_settings_1'), 'seolab_ai_enabled' => 'yes'];
    p1a_assert(p1a_status($env->controller()->settings('POST', $request)) === 400, 'valore yes non 400');
    p1a_assert($env->saved === null, 'opzione modificata con valore non valido');
});

// ---------------------------------------------------------------------------
// Off, compatibilita', runtime, connettore
// ---------------------------------------------------------------------------

p1a_case('off: opt-in spento -> 403, nessun lookup del connettore, nessuna riserva', static function (): void {
    $env = new P1aEnv();
    $env->optIn = false;
    $response = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    p1a_assert(p1a_status($response) === 403, 'opt-in spento non 403');
    p1a_assert(strpos($response['body'], 'AI review is off') !== false, 'messaggio opt-in spento assente');
    p1a_assert($env->configuredLookups === 0 && $env->factoryCalls === 0, 'connettore o provider toccati con toggle spento');
    p1a_assert($env->store->reads === 0 && $env->store->writes === 0, 'registro toccato con toggle spento');
});

p1a_case('off dopo opt-in: la conferma e\' rifiutata e non tocca il registro', static function (): void {
    $env = new P1aEnv();
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    p1a_assert(p1a_status($preview) === 200, 'anteprima non 200');
    $env->optIn = false;
    $response = $env->controller()->confirm('POST', p1a_confirm_request($preview['body']));
    p1a_assert(p1a_status($response) === 403, 'conferma con opt-in spento non 403');
    p1a_assert($env->modelCalls === 0 && $env->store->writes === 0, 'riserva o modello con opt-in spento');
});

p1a_case('runtime: Core senza AI o versione precedente -> 503 in anteprima, nessun lookup', static function (): void {
    foreach (['core_disabled', 'unsupported_runtime'] as $runtime) {
        $env = new P1aEnv();
        $env->runtime = $runtime;
        $response = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
        p1a_assert(p1a_status($response) === 503, $runtime . ' non 503');
        p1a_assert($env->configuredLookups === 0, $runtime . ' ha letto il connettore');
    }
});

p1a_case('impostazioni: attivazione con runtime assente -> 409, la P0 resta', static function (): void {
    $env = new P1aEnv();
    $env->runtime = 'unsupported_runtime';
    $request = ['seo_lab_nonce' => $env->nonce('seo_lab_ai_settings_1'), 'seolab_ai_enabled' => '1'];
    p1a_assert(p1a_status($env->controller()->settings('POST', $request)) === 409, 'attivazione senza Core AI non 409');
    p1a_assert($env->saved === null, 'opzione attivata senza Core AI');
});

p1a_case('impostazioni: spegnimento sempre consentito, anche con Core disattivato', static function (): void {
    $env = new P1aEnv();
    $env->runtime = 'core_disabled';
    $request = ['seo_lab_nonce' => $env->nonce('seo_lab_ai_settings_1'), 'seolab_ai_enabled' => '0'];
    $response = $env->controller()->settings('POST', $request);
    p1a_assert(p1a_status($response) === 302, 'spegnimento non riuscito');
    p1a_assert($env->saved === '0', 'valore 0 non salvato');
    p1a_assert($response['location'] === 'https://site.example/wp-admin/options-general.php?page=seo-lab-ai&saved=1', 'redirect inatteso');
    p1a_assert($response['body'] === '', 'redirect con contenuto');
});

p1a_case('connettore assente: anteprima 503 unconfigured, nessun invio e nessuna riserva', static function (): void {
    $env = new P1aEnv();
    $response = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07, 'anthropic', 'claude-test-1'));
    p1a_assert(p1a_status($response) === 503, 'connettore assente non 503');
    p1a_assert($env->configuredLookups === 1 && $env->factoryCalls === 0 && $env->store->writes === 0, 'provider creato o riserva senza connettore');
});

p1a_case('rapporto senza finding o contenuto non idoneo -> 409', static function (): void {
    $env = new P1aEnv();
    $env->eligible = false;
    p1a_assert(p1a_status($env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07))) === 409, 'contenuto non idoneo non 409');
    $env->eligible = true;
    $none = p1a_fixture('03');
    p1a_assert($none['findings'] === [], 'fixture 03 con finding: caso non valido');
    $env->archive->save(new ScanReport($none));
    $request = p1a_preview_request($env, $none['scan_id']);
    p1a_assert(p1a_status($env->controller()->preview('POST', $request)) === 409, 'rapporto senza finding non 409');
    p1a_assert($env->store->writes === 0 && $env->modelCalls === 0, 'riserva su rapporto senza finding');
});

p1a_case('rapporto rimosso o UUID sconosciuto -> 404', static function (): void {
    $env = new P1aEnv();
    $unknown = '00000000-0000-4000-8000-0000000000bb';
    p1a_assert(p1a_status($env->controller()->preview('POST', p1a_preview_request($env, $unknown))) === 404, 'UUID sconosciuto non 404');
});

// ---------------------------------------------------------------------------
// Anteprima: letterale, nessuna scrittura, cap e riserva tecnica
// ---------------------------------------------------------------------------

p1a_case('anteprima IT (fixture 07): system e data letterali, confronto a byte dopo decodifica', static function (): void {
    $env = new P1aEnv();
    $response = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07, 'openai', 'gpt-test-1', 'it'));
    p1a_assert(p1a_status($response) === 200, 'anteprima IT non 200');
    $review = ReviewRequest::fromReport(new ScanReport(p1a_fixture('07')), 'it');
    $prompt = ReviewPrompt::fromRequest($review);
    preg_match_all('#<pre>(.*?)</pre>#s', $response['body'], $pre);
    p1a_assert(count($pre[1]) === 2, 'attesi due blocchi pre');
    p1a_assert(html_entity_decode($pre[1][0], ENT_QUOTES, 'UTF-8') === $prompt['system'], 'system non letterale');
    p1a_assert(html_entity_decode($pre[1][1], ENT_QUOTES, 'UTF-8') === $prompt['data'], 'data non letterali');
    p1a_assert(strpos($response['body'], 'Instructions (system)') !== false, 'etichetta system assente');
});

p1a_case('anteprima EN (fixture 10): mostra lingua, modello esatto e cap 768', static function (): void {
    $env = new P1aEnv();
    $response = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_10, 'openai', 'gpt-test-1', 'en'));
    p1a_assert(p1a_status($response) === 200, 'anteprima EN non 200');
    p1a_assert(strpos($response['body'], 'gpt-test-1') !== false, 'modello esatto assente');
    p1a_assert(strpos($response['body'], '768 tokens') !== false, 'cap 768 assente');
    p1a_assert(strpos($response['body'], 'English') !== false, 'lingua EN assente');
    p1a_assert(strpos($response['body'], 'not the provider invoice') !== false, 'riserva non descritta come stima');
});

p1a_case('anteprima: nessuna scrittura, nessuna riserva, nessuna chiamata al modello', static function (): void {
    $env = new P1aEnv();
    $before = ReviewConfirmation::reportDigest($env->archive->find(P1A_UUID_07));
    $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    p1a_assert($env->store->writes === 0 && $env->store->value === null, 'anteprima ha scritto il registro');
    p1a_assert($env->modelCalls === 0, 'anteprima ha chiamato il modello');
    p1a_assert(ReviewConfirmation::reportDigest($env->archive->find(P1A_UUID_07)) === $before, 'rapporto mutato dall\'anteprima');
});

p1a_case('vista: entita\' gia\' presenti nel testo restano visibili come entita\' (doppia codifica)', static function (): void {
    $html = AiReviewView::preview([
        'reportId' => P1A_UUID_07, 'provider' => 'openai', 'model' => 'gpt-test-1', 'language' => 'it',
        'system' => 'a &amp; <b>', 'data' => '{"x":"&lt;"}', 'units' => 1, 'maxOutput' => 768, 'reasoningEffort' => null,
        'issuedAt' => 1, 'confirmationId' => str_repeat('a', 32),
        'phaseNonce' => 'p', 'contextNonce' => 'c',
    ], 'https://site.example/wp-admin/admin-post.php');
    preg_match('#<pre>(.*?)</pre>#s', $html, $m);
    p1a_assert($m[1] === 'a &amp;amp; &lt;b&gt;', 'codifica del system non letterale: ' . $m[1]);
});

p1a_case('anteprima gpt-6-luna: cap 768, profilo effort none e addebito dichiarato; nessun output non verificato', static function (): void {
    $env = new P1aEnv();
    $response = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07, 'openai', 'gpt-6-luna', 'it'));
    p1a_assert(p1a_status($response) === 200, 'anteprima non 200');
    p1a_assert(strpos($response['body'], '768 tokens') !== false, 'cap 768 assente');
    p1a_assert(strpos($response['body'], 'gpt-6-luna: reasoning effort none for this request') !== false, 'profilo Luna assente');
    p1a_assert(strpos($response['body'], 'not verified') === false, 'output non verificato mostrato per Luna');
    p1a_assert(strpos($response['body'], 'Sending the request can be charged by the provider') !== false, 'avviso di addebito assente');
    p1a_assert(strpos($response['body'], 'reasoning tokens count toward the maximum output') !== false, 'token di ragionamento fuori dal cap');
});

p1a_case('anteprima altro ID OpenAI: comportamento del connettore, output a questo limite non verificato', static function (): void {
    $env = new P1aEnv();
    $response = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07, 'openai', 'gpt-test-1', 'it'));
    p1a_assert(p1a_status($response) === 200, 'anteprima non 200');
    p1a_assert(strpos($response['body'], 'The model uses the connector default. Output at this limit is not verified for this model.') !== false, 'comportamento del connettore assente');
    p1a_assert(strpos($response['body'], 'reasoning effort none') === false, 'profilo Luna applicato a un altro ID');
});

p1a_case('anteprima Anthropic: addebito dichiarato, nessuna riga di ragionamento', static function (): void {
    $env = new P1aEnv();
    $env->providers['anthropic'] = true;
    $response = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07, 'anthropic', 'claude-test-1', 'en'));
    p1a_assert(p1a_status($response) === 200, 'anteprima non 200');
    p1a_assert(strpos($response['body'], 'Sending the request can be charged by the provider') !== false, 'avviso di addebito assente');
    p1a_assert(strpos($response['body'], '<th scope="row">Reasoning</th>') === false, 'riga di ragionamento per Anthropic');
});

p1a_case('conferma gpt-6-luna: nonce di contesto creato prima del profilo -> 409, nessuna ammissione, nessuna POST', static function (): void {
    $env = new P1aEnv();
    $report = $env->archive->find(P1A_UUID_07);
    $prompt = ReviewPrompt::fromRequest(ReviewRequest::fromReport($report, 'it'));
    $confirmationId = str_repeat('c', 32);
    // Tupla come prima dell'aggiunta del profilo: nessuna chiave reasoning_effort.
    $oldDigest = ReviewConfirmation::digest(ReviewConfirmation::tuple(
        $env->blog, $env->user, P1A_UUID_07, ReviewConfirmation::reportDigest($report), 'openai', 'gpt-6-luna', 'it', $prompt, $env->now, $confirmationId
    ));
    $request = [
        'report' => P1A_UUID_07,
        'provider' => 'openai',
        'model' => 'gpt-6-luna',
        'language' => 'it',
        'issued_at' => (string) $env->now,
        'confirmation_id' => $confirmationId,
        'seo_lab_nonce' => $env->nonce(ReviewConfirmation::phaseAction('confirm', $env->blog, P1A_UUID_07)),
        'seo_lab_ctx_nonce' => $env->nonce(ReviewConfirmation::contextAction((string) $oldDigest)),
    ];
    $response = $env->controller()->confirm('POST', $request);
    p1a_assert(p1a_status($response) === 409, 'stato ' . p1a_status($response));
    p1a_assert($env->modelCalls === 0 && $env->store->writes === 0, 'ammissione o POST con nonce pre-profilo');

    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07, 'openai', 'gpt-6-luna', 'it'));
    p1a_assert(p1a_status($env->controller()->confirm('POST', p1a_confirm_request($preview['body']))) === 200, 'anteprima nuova non confermata');
    p1a_assert($env->modelCalls === 1, 'modello non chiamato una volta dopo l\'anteprima nuova');
});

// ---------------------------------------------------------------------------
// Conferma: alterazioni, eta', replay, esito
// ---------------------------------------------------------------------------

p1a_case('conferma: anteprima e invio riusciti, un solo tentativo al modello e una riga registrata', static function (): void {
    $env = new P1aEnv();
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $response = $env->controller()->confirm('POST', p1a_confirm_request($preview['body']));
    p1a_assert(p1a_status($response) === 200, 'conferma non 200: ' . $response['body']);
    p1a_assert($response['location'] === null, 'risultato in redirect');
    p1a_assert($env->modelCalls === 1, 'modello non chiamato una volta');
    p1a_assert($env->store->writes === 1 && $env->store->value !== null, 'riga del registro non scritta');
    $stored = json_decode((string) $env->store->value, true);
    p1a_assert($stored['v'] === 1 && count($stored['rows']) === 1, 'formato del registro inatteso');
    $row = $stored['rows'][0];
    p1a_assert($row[1] === hash('sha256', (string) p1a_field($preview['body'], 'confirmation_id')), 'hash della conferma errato');
    p1a_assert(strpos((string) $env->store->value, (string) p1a_field($preview['body'], 'confirmation_id')) === false, 'identificatore in chiaro nel registro');
    p1a_assert(strpos($response['body'], 'Explanation') !== false && strpos($response['body'], 'Suggested test') !== false, 'etichette del risultato assenti');
    p1a_assert(strpos($response['body'], 'Hypothesis') !== false, 'ipotesi marcata assente');
    p1a_assert(strpos($response['body'], 'Rule ') !== false, 'etichette delle ref assenti');
});

p1a_case('conferma: replay dello stesso invio -> 429 senza secondo tentativo', static function (): void {
    $env = new P1aEnv();
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $request = p1a_confirm_request($preview['body']);
    p1a_assert(p1a_status($env->controller()->confirm('POST', $request)) === 200, 'primo invio non 200');
    $env->now += 60;
    p1a_assert(p1a_status($env->controller()->confirm('POST', $request)) === 429, 'replay non 429');
    p1a_assert($env->modelCalls === 1, 'replay ha raggiunto il modello');
});

p1a_case('conferma: ogni alterazione di campo firmato viene rifiutata senza riserva', static function (): void {
    $alterations = [
        'modello' => ['model', 'gpt-other-1'],
        'lingua' => ['language', 'en'],
        'provider' => ['provider', 'anthropic'],
        'report' => ['report', P1A_UUID_10],
        'istante' => ['issued_at', null],
        'identificatore' => ['confirmation_id', str_repeat('b', 32)],
    ];
    foreach ($alterations as $label => [$field, $value]) {
        $env = new P1aEnv();
        $env->providers['anthropic'] = true;
        $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
        $request = p1a_confirm_request($preview['body']);
        $request[$field] = $value ?? (string) ((int) $request[$field] + 1);
        $status = p1a_status($env->controller()->confirm('POST', $request));
        p1a_assert($status === 403 || $status === 409, $label . ': stato ' . $status);
        p1a_assert($env->modelCalls === 0 && $env->store->writes === 0, $label . ': riserva o modello');
    }
});

p1a_case('conferma: utente o sito diversi dalla preview -> 403 prima della riserva', static function (): void {
    $env = new P1aEnv();
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $request = p1a_confirm_request($preview['body']);
    $env->user = 9;
    p1a_assert(p1a_status($env->controller()->confirm('POST', $request)) === 403, 'utente diverso non 403');
    $env->user = 7;
    $env->blog = '2';
    p1a_assert(p1a_status($env->controller()->confirm('POST', $request)) === 403, 'sito diverso non 403');
    p1a_assert($env->store->writes === 0 && $env->modelCalls === 0, 'riserva con contesto diverso');
});

p1a_case('conferma: stesso UUID con report modificato -> 409, nuova anteprima richiesta', static function (): void {
    $env = new P1aEnv();
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $fixture = p1a_fixture('07');
    $fixture['findings'][0]['severity'] = $fixture['findings'][0]['severity'] === 'error' ? 'warning' : 'error';
    $env->archive->save(new ScanReport($fixture));
    p1a_assert(p1a_status($env->controller()->confirm('POST', p1a_confirm_request($preview['body']))) === 409, 'report modificato non 409');
    p1a_assert($env->store->writes === 0 && $env->modelCalls === 0, 'riserva con report modificato');
});

p1a_case('conferma: eta\' 0, 300 accettate; 301 e istante futuro rifiutati', static function (): void {
    $env = new P1aEnv();
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $request = p1a_confirm_request($preview['body']);
    $env->now = P1A_TODAY + 300;
    p1a_assert(p1a_status($env->controller()->confirm('POST', $request)) === 200, 'eta 300 s non accettata');

    $env = new P1aEnv();
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $env->now = P1A_TODAY + 301;
    p1a_assert(p1a_status($env->controller()->confirm('POST', p1a_confirm_request($preview['body']))) === 409, 'eta 301 s non 409');

    $env = new P1aEnv();
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $env->now = P1A_TODAY - 1;
    p1a_assert(p1a_status($env->controller()->confirm('POST', p1a_confirm_request($preview['body']))) === 409, 'istante futuro non 409');
    p1a_assert($env->store->writes === 0, 'riserva con conferma scaduta o futura');
});

p1a_case('conferma: campi mancanti o di tipo errato -> 400 o 403, mai riserva', static function (): void {
    $env = new P1aEnv();
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $request = p1a_confirm_request($preview['body']);
    $bad = $request;
    $bad['confirmation_id'] = ['x'];
    p1a_assert(p1a_status($env->controller()->confirm('POST', $bad)) === 400, 'identificatore array non 400');
    $bad = $request;
    $bad['issued_at'] = '12abc';
    p1a_assert(p1a_status($env->controller()->confirm('POST', $bad)) === 400, 'istante non numerico non 400');
    $bad = $request;
    $bad['seo_lab_ctx_nonce'] = null;
    p1a_assert(p1a_status($env->controller()->confirm('POST', $bad)) === 403, 'nonce di contesto mancante non 403');
    p1a_assert($env->store->writes === 0, 'riserva con campi non validi');
});

p1a_case('conferma: connettore non disponibile prima della riserva -> nessun consumo, ripresentabile', static function (): void {
    $env = new P1aEnv();
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $request = p1a_confirm_request($preview['body']);
    $env->providers['openai'] = false;
    p1a_assert(p1a_status($env->controller()->confirm('POST', $request)) === 503, 'connettore assente in conferma non 503');
    p1a_assert($env->store->writes === 0, 'conferma rifiutata prima della riserva ha consumato il registro');
    $env->providers['openai'] = true;
    p1a_assert(p1a_status($env->controller()->confirm('POST', $request)) === 200, 'conferma non ripresentabile entro la scadenza');
});

p1a_case('conferma: errore del provider dopo la riserva mantiene il conteggio', static function (): void {
    $env = new P1aEnv();
    $env->script = 'provider_error';
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $response = $env->controller()->confirm('POST', p1a_confirm_request($preview['body']));
    p1a_assert(p1a_status($response) === 503, 'errore provider non 503');
    p1a_assert($env->store->writes === 1, 'tentativo ammesso non conteggiato dopo errore');
    p1a_assert(strpos($response['body'], 'cannot answer now') !== false, 'messaggio locale assente');
    p1a_assert(strpos($response['body'], P1A_SENTINEL) === false, 'segreto nell\'esito');
});

p1a_case('conferma: risposta troncata -> 503 output_truncated, una riserva, replay bloccato, nessun testo parziale', static function (): void {
    $env = new P1aEnv();
    $env->script = 'output_truncated';
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $request = p1a_confirm_request($preview['body']);
    $response = $env->controller()->confirm('POST', $request);
    p1a_assert(p1a_status($response) === 503, 'troncamento non 503');
    p1a_assert(strpos($response['body'], 'reached the output limit and was not shown') !== false, 'messaggio output_truncated assente');
    p1a_assert($env->store->writes === 1, 'tentativo ammesso non conteggiato una volta');
    p1a_assert(strpos($response['body'], 'schema_version') === false && strpos($response['body'], P1A_SENTINEL) === false, 'testo parziale o segreto nell\'esito');
    p1a_assert(p1a_status($env->controller()->confirm('POST', $request)) !== 200, 'replay della conferma non bloccato');
    p1a_assert($env->modelCalls === 1 && $env->store->writes === 1, 'replay ha inviato o riservato di nuovo');
});

// ---------------------------------------------------------------------------
// Output: testo non attendibile, escaping, ref, hash del rapporto
// ---------------------------------------------------------------------------

p1a_case('output: caratteri bidi rimossi e separatori Unicode normalizzati a spazio', static function (): void {
    p1a_assert(AiReviewView::sanitizeText("A\u{202E}B\u{2066}C\u{200F}") === 'ABC', 'bidi non rimossi');
    p1a_assert(AiReviewView::sanitizeText("X\u{2028}Y\u{00A0}Z\u{3000}W") === 'X Y Z W', 'separatori non normalizzati');
    p1a_assert(AiReviewView::sanitizeText("\u{202E}\u{200F}") === '', 'testo vuoto non riconosciuto');
});

p1a_case('output: item svuotato dalla pulizia -> 503 invalid_response', static function (): void {
    $env = new P1aEnv();
    $env->script = 'bidi_only';
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $response = $env->controller()->confirm('POST', p1a_confirm_request($preview['body']));
    p1a_assert(p1a_status($response) === 503, 'item vuoto non 503');
    p1a_assert(strpos($response['body'], 'was not valid') !== false, 'messaggio invalid_response assente');
});

p1a_case('output: ref inventata nella risposta -> 503 invalid_response senza testo grezzo', static function (): void {
    $env = new P1aEnv();
    $env->script = 'unknown_ref';
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $response = $env->controller()->confirm('POST', p1a_confirm_request($preview['body']));
    p1a_assert(p1a_status($response) === 503, 'ref inventata non 503');
    p1a_assert(strpos($response['body'], 'NOPE') === false, 'ref inventata mostrata');
});

p1a_case('output: markup del modello escapato, nessun HTML attivo', static function (): void {
    $env = new P1aEnv();
    $env->script = 'escape';
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $response = $env->controller()->confirm('POST', p1a_confirm_request($preview['body']));
    p1a_assert(p1a_status($response) === 200, 'risposta con markup non 200');
    p1a_assert(strpos($response['body'], '<script>alert') === false, 'script attivo nel risultato');
    p1a_assert(strpos($response['body'], '&lt;script&gt;') !== false, 'markup non escapato');
});

p1a_case('output: entita\' numeriche o nominate nel testo del modello restano testo inerte', static function (): void {
    $env = new P1aEnv();
    $env->script = 'entity';
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $response = $env->controller()->confirm('POST', p1a_confirm_request($preview['body']));
    p1a_assert(p1a_status($response) === 200, 'risposta con entita non 200');
    p1a_assert(strpos($response['body'], '&amp;#x202E;') !== false, 'entita numerica non ricodificata');
    p1a_assert(strpos($response['body'], '&amp;lrm;') !== false, 'entita nominata non ricodificata');
    p1a_assert(strpos($response['body'], '&#x202E;') === false && strpos($response['body'], '&lrm;') === false, 'entita attiva nel risultato');
});

p1a_case('registro: nessun modello, UUID del rapporto o segreto nella riga scritta', static function (): void {
    $env = new P1aEnv();
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $env->controller()->confirm('POST', p1a_confirm_request($preview['body']));
    $value = (string) $env->store->value;
    p1a_assert($value !== '', 'riga non scritta');
    p1a_assert(strpos($value, 'gpt-test-1') === false, 'modello nel registro');
    p1a_assert(strpos($value, P1A_UUID_07) === false, 'UUID del rapporto nel registro');
    p1a_assert(strpos($value, P1A_SENTINEL) === false, 'segreto nel registro');
});

p1a_case('output: separatori Unicode del testo mostrati come spazi', static function (): void {
    $env = new P1aEnv();
    $env->script = 'separators';
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $response = $env->controller()->confirm('POST', p1a_confirm_request($preview['body']));
    p1a_assert(p1a_status($response) === 200, 'risposta con separatori non 200');
    p1a_assert(strpos($response['body'], 'Uno due tre') !== false, 'separatori non normalizzati nel risultato');
});

p1a_case('output: report archiviato invariato dopo anteprima e invio', static function (): void {
    $env = new P1aEnv();
    $before = ReviewConfirmation::reportDigest($env->archive->find(P1A_UUID_07));
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $env->controller()->confirm('POST', p1a_confirm_request($preview['body']));
    p1a_assert(ReviewConfirmation::reportDigest($env->archive->find(P1A_UUID_07)) === $before, 'rapporto mutato dal flusso AI');
});

p1a_case('output: risultato senza credenziali o corpo grezzo e senza redirect', static function (): void {
    $env = new P1aEnv();
    $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
    $response = $env->controller()->confirm('POST', p1a_confirm_request($preview['body']));
    p1a_assert($response['location'] === null, 'risultato in Location');
    p1a_assert(strpos($response['body'], P1A_SENTINEL) === false, 'segreto nel risultato');
    p1a_assert(strpos($response['body'], 'Back to the report') !== false, 'link alla vista report assente');
});

// ---------------------------------------------------------------------------
// Riserva: quote, cooldown, replay, orologio, conflitti (registro in memoria)
// ---------------------------------------------------------------------------

/** Identificatore di conferma deterministico per i casi di registro. */
function p1a_conf(int $n): string
{
    return sprintf('%032x', 0xabc000 + $n);
}

p1a_case('riserva: quota utente 5/6 e cooldown 9/10 secondi', static function (): void {
    $store = new FakeStore();
    $ledger = new WordPressAiLedger($store);
    $t = P1A_TODAY;
    for ($i = 0; $i < 5; $i++) {
        p1a_assert($ledger->admit(1, 3, p1a_conf($i), $t + 10 * $i, $t + 10 * $i, 100) === null, 'ammissione ' . $i . ' rifiutata');
    }
    p1a_assert($ledger->admit(1, 3, p1a_conf(9), $t + 60, $t + 60, 100) === 'rate_limited', 'sesta richiesta utente non limitata');

    $store = new FakeStore();
    $ledger = new WordPressAiLedger($store);
    p1a_assert($ledger->admit(1, 4, p1a_conf(20), $t, $t, 100) === null, 'prima richiesta rifiutata');
    p1a_assert($ledger->admit(1, 4, p1a_conf(21), $t + 9, $t + 9, 100) === 'rate_limited', 'cooldown 9 s non applicato');
    p1a_assert($ledger->admit(1, 4, p1a_conf(22), $t + 10, $t + 10, 100) === null, 'cooldown 10 s non rispettato');
});

p1a_case('riserva: quota unita utente 16384 esatte ammesse, un\'unita in piu\' rifiutata', static function (): void {
    $store = new FakeStore();
    $ledger = new WordPressAiLedger($store);
    $t = P1A_TODAY;
    p1a_assert($ledger->admit(1, 5, p1a_conf(1), $t, $t, 16384) === null, 'quota esatta non ammessa');
    p1a_assert($ledger->admit(1, 5, p1a_conf(2), $t + 20, $t + 20, 1) === 'rate_limited', 'unita oltre la quota utente ammesse');
});

p1a_case('riserva: quota del sito 20 richieste e 65536 unita', static function (): void {
    $store = new FakeStore();
    $ledger = new WordPressAiLedger($store);
    $t = P1A_TODAY;
    for ($i = 0; $i < 20; $i++) {
        $user = 10 + intdiv($i, 5);
        p1a_assert($ledger->admit(1, $user, p1a_conf($i), $t + 10 * $i, $t + 10 * $i, 100) === null, 'richiesta ' . $i . ' di sito rifiutata');
    }
    p1a_assert($ledger->admit(1, 30, p1a_conf(99), $t + 300, $t + 300, 100) === 'budget_exhausted', 'ventunesima richiesta del sito non limitata');

    $store = new FakeStore();
    $ledger = new WordPressAiLedger($store);
    for ($u = 40; $u < 44; $u++) {
        p1a_assert($ledger->admit(1, $u, p1a_conf($u), $t, $t, 16384) === null, 'quota unita del sito non ammessa a ' . $u);
    }
    p1a_assert($ledger->admit(1, 50, p1a_conf(50), $t, $t, 1) === 'budget_exhausted', 'unita del sito oltre 65536 ammesse');
});

p1a_case('riserva: cambio giorno UTC azzera le quote, il replay di ieri resta bloccato', static function (): void {
    $store = new FakeStore();
    $ledger = new WordPressAiLedger($store);
    $dayStart = P1A_TODAY - (P1A_TODAY % 86400);
    $lastOfYesterday = $dayStart - 10;
    for ($i = 0; $i < 5; $i++) {
        $ledger->admit(1, 60, p1a_conf(100 + $i), $lastOfYesterday - 100 + $i * 10, $lastOfYesterday - 100 + $i * 10, 100);
    }
    $t = $dayStart + 5;
    p1a_assert($ledger->admit(1, 60, p1a_conf(200), $t, $t, 100) === null, 'quota utente non azzerata al cambio giorno');
    p1a_assert($ledger->admit(1, 61, p1a_conf(100), $t + 5, $t + 5, 100) === 'rate_limited', 'replay di ieri non bloccato');
});

p1a_case('riserva: replay a eta 300 s, esattamente a mezzanotte UTC, bloccato', static function (): void {
    $store = new FakeStore();
    $ledger = new WordPressAiLedger($store);
    $dayStart = P1A_TODAY - (P1A_TODAY % 86400);
    $issued = $dayStart - 300;
    p1a_assert($ledger->admit(1, 60, p1a_conf(700), $issued, $issued, 100) === null, 'prima ammissione rifiutata');
    p1a_assert($ledger->admit(1, 61, p1a_conf(700), $issued, $dayStart, 100) === 'rate_limited', 'replay a eta 300 s dopo mezzanotte ammesso');
});

p1a_case('conferma: permesso, sito o opt-in revocati prima della riserva -> 403, nessun invio, registro invariato', static function (): void {
    $revocations = [
        'sito' => static function (P1aEnv $env): void {
            $env->siteOk = false;
        },
        'permesso' => static function (P1aEnv $env): void {
            $env->can = false;
        },
        'opt-in' => static function (P1aEnv $env): void {
            $env->optIn = false;
        },
    ];
    foreach ($revocations as $name => $revoke) {
        $env = new P1aEnv();
        $preview = $env->controller()->preview('POST', p1a_preview_request($env, P1A_UUID_07));
        $env->beforeAdmit = static function () use ($env, $revoke): void {
            $revoke($env);
        };
        $value = $env->store->value;
        $writes = $env->store->writes;
        p1a_assert(p1a_status($env->controller()->confirm('POST', p1a_confirm_request($preview['body']))) === 403, $name . ' revocato: stato non 403');
        p1a_assert($env->modelCalls === 0, $name . ' revocato: invio al modello');
        p1a_assert($env->store->value === $value && $env->store->writes === $writes, $name . ' revocato: registro modificato');
    }
});

p1a_case('riserva: orologio arretrato -> budget_exhausted, registro intatto', static function (): void {
    $store = new FakeStore();
    $ledger = new WordPressAiLedger($store);
    $ledger->admit(1, 70, p1a_conf(300), P1A_TODAY + 1000, P1A_TODAY + 1000, 100);
    $writes = $store->writes;
    $value = $store->value;
    p1a_assert($ledger->admit(1, 71, p1a_conf(301), P1A_TODAY, P1A_TODAY, 100) === 'budget_exhausted', 'orologio arretrato ammesso');
    p1a_assert($store->writes === $writes && $store->value === $value, 'registro modificato dal rifiuto');
});

p1a_case('riserva: stato corrotto o lettura fallita -> budget_exhausted senza scrittura', static function (): void {
    $corrupt = ['not json', '{"v":2,"rows":[]}', '{"v":1,"rows":[[1,"abc",1,1,1]]}', '{"v":1,"rows":[[1,"' . str_repeat('a', 64) . '",-1,1,1]]}'];
    foreach ($corrupt as $value) {
        $store = new FakeStore();
        $store->value = $value;
        $ledger = new WordPressAiLedger($store);
        p1a_assert($ledger->admit(1, 80, p1a_conf(400), P1A_TODAY, P1A_TODAY, 100) === 'budget_exhausted', 'stato corrotto ammesso: ' . $value);
        p1a_assert($store->writes === 0, 'scrittura su stato corrotto');
    }
    $store = new FakeStore();
    $store->readError = true;
    $ledger = new WordPressAiLedger($store);
    p1a_assert($ledger->admit(1, 80, p1a_conf(401), P1A_TODAY, P1A_TODAY, 100) === 'budget_exhausted', 'lettura fallita ammessa');
    p1a_assert($store->writes === 0, 'scrittura dopo lettura fallita');
});

p1a_case('riserva: conflitto INSERT concorrente -> nuovo tentativo, esito corretto', static function (): void {
    $store = new FakeStore();
    $store->forceInsertConflicts = 1;
    $ledger = new WordPressAiLedger($store);
    p1a_assert($ledger->admit(1, 90, p1a_conf(500), P1A_TODAY, P1A_TODAY, 100) === null, 'conflitto INSERT non risolto');
    $rows = json_decode((string) $store->value, true)['rows'];
    p1a_assert(count($rows) === 2 && $rows[0][0] === 999 && $rows[1][0] === 90, 'riga dell\'altro scrittore non conservata o riga propria assente');
});

p1a_case('riserva: CAS con due conflitti ancora risolto; tre conflitti -> budget_exhausted', static function (): void {
    $store = new FakeStore();
    $store->value = json_encode(['v' => 1, 'rows' => []]);
    $store->forceCasConflicts = 2;
    $ledger = new WordPressAiLedger($store);
    p1a_assert($ledger->admit(1, 91, p1a_conf(501), P1A_TODAY, P1A_TODAY, 100) === null, 'due conflitti CAS non risolti');

    $store = new FakeStore();
    $store->value = json_encode(['v' => 1, 'rows' => []]);
    $store->forceCasConflicts = 3;
    $ledger = new WordPressAiLedger($store);
    p1a_assert($ledger->admit(1, 92, p1a_conf(502), P1A_TODAY, P1A_TODAY, 100) === 'budget_exhausted', 'tre conflitti CAS non chiusi');
    p1a_assert($store->writes === 3, 'tentativi CAS diversi da tre');
});

p1a_case('riserva: richieste concorrenti con la stessa conferma -> una sola ammissione', static function (): void {
    $store = new FakeStore();
    $ledger = new WordPressAiLedger($store);
    $store->value = json_encode(['v' => 1, 'rows' => []]);
    $wins = 0;
    $store->beforeCas = static function () use ($ledger, &$wins): void {
        // La richiesta concorrente con la stessa conferma arriva prima del nostro CAS.
        if ($ledger->admit(1, 95, p1a_conf(600), P1A_TODAY, P1A_TODAY, 100) === null) {
            $wins++;
        }
    };
    $verdict = $ledger->admit(1, 95, p1a_conf(600), P1A_TODAY, P1A_TODAY, 100);
    p1a_assert($wins === 1, 'concorrente non ammessa');
    p1a_assert($verdict === 'rate_limited', 'secondo tentativo non bloccato: ' . var_export($verdict, true));
    p1a_assert(count(json_decode((string) $store->value, true)['rows']) === 1, 'piu di una riga per la stessa conferma');
});

p1a_case('riserva: registro senza identificatore in chiaro e con hash della conferma', static function (): void {
    $store = new FakeStore();
    $ledger = new WordPressAiLedger($store);
    $id = p1a_conf(700);
    $ledger->admit(1, 96, $id, P1A_TODAY, P1A_TODAY, 100);
    p1a_assert(strpos((string) $store->value, $id) === false, 'identificatore in chiaro');
    p1a_assert(strpos((string) $store->value, hash('sha256', $id)) !== false, 'hash della conferma assente');
});

p1a_case('riserva: cooldown a cavallo di mezzanotte non si perde con la potatura', static function (): void {
    $dayStart = P1A_TODAY - (P1A_TODAY % 86400);
    $store = new FakeStore();
    // Ammessa un secondo prima di mezzanotte, con conferma gia' oltre i 300 s: solo il cooldown la tiene viva.
    $store->value = json_encode(['v' => 1, 'rows' => [[110, hash('sha256', p1a_conf(900)), $dayStart - 299, $dayStart - 1, 100]]]);
    $ledger = new WordPressAiLedger($store);
    $t = $dayStart + 5;
    p1a_assert($ledger->admit(1, 110, p1a_conf(901), $t, $t, 100) === 'rate_limited', 'cooldown perso a cavallo di mezzanotte');
    p1a_assert($store->writes === 0, 'registro scritto durante il cooldown');
});

p1a_case('vista P0: con toggle spento Plugin::aiReviewForm restituisce stringa vuota senza lookup', static function (): void {
    // Runtime disponibile e connettore configurato: solo il toggle spento deve impedire il form e il lookup.
    $GLOBALS['p1a']['opt'] = [];
    $GLOBALS['p1a']['registry'] = new P1aRegistry();
    $GLOBALS['wp_version'] = '7.1.5';
    $report = new ScanReport(p1a_fixture('07'));
    p1a_assert(SeoLab\Admin\Plugin::aiReviewForm($report) === '', 'form visibile con toggle spento');
    p1a_assert($GLOBALS['p1a']['lookups'] === 0, 'registry consultato con toggle spento');
});

p1a_case('vista P0: con opt-in ma senza connettore locale il form non compare', static function (): void {
    $GLOBALS['p1a']['opt'] = ['seolab_ai_enabled' => '1'];
    $GLOBALS['p1a']['registry'] = new P1aRegistry();
    $GLOBALS['p1a']['registry']->has = false;
    $GLOBALS['wp_version'] = '7.1.5';
    $report = new ScanReport(p1a_fixture('07'));
    p1a_assert(SeoLab\Admin\Plugin::aiReviewForm($report) === '', 'form visibile senza connettore');
    p1a_assert($GLOBALS['p1a']['lookups'] === 2, 'lookup dei due provider attesi: ' . $GLOBALS['p1a']['lookups']);
});

p1a_case('vista P0: con opt-in, runtime e connettore il form porta nonce di anteprima e nessun contenuto del report', static function (): void {
    $GLOBALS['p1a']['opt'] = ['seolab_ai_enabled' => '1'];
    $registry = new P1aRegistry();
    $GLOBALS['p1a']['registry'] = $registry;
    $GLOBALS['wp_version'] = '7.1.5';
    $report = new ScanReport(p1a_fixture('07'));
    $html = SeoLab\Admin\Plugin::aiReviewForm($report);
    p1a_assert(strpos($html, 'name="action" value="seo_lab_ai_preview"') !== false, 'form di anteprima assente');
    p1a_assert(strpos($html, 'name="report" value="' . P1A_UUID_07 . '"') !== false, 'UUID del rapporto assente dal form');
    p1a_assert(strpos($html, 'name="provider"') !== false && strpos($html, 'value="openai"') !== false, 'provider configurato assente');
    p1a_assert(strpos($html, 'value="anthropic"') === false, 'provider non configurato offerto');
    p1a_assert(strpos($html, 'Spiegazione') === false && strpos($html, $report->findings()[0]->ruleId()) === false, 'contenuto del rapporto nel form');
});

p1a_case('riserva: stima operativa in byte UTF-8 piu\' margine fisso', static function (): void {
    p1a_assert(WordPressAiLedger::estimateUnits(['system' => "\u{00E0}b", 'data' => '{}']) === 3 + 2 + 1024, 'stima non in byte UTF-8');
});

p1a_case('riserva: identificatore non valido o unita nulle -> budget_exhausted senza scrittura', static function (): void {
    $store = new FakeStore();
    $ledger = new WordPressAiLedger($store);
    p1a_assert($ledger->admit(1, 97, 'corto', P1A_TODAY, P1A_TODAY, 100) === 'budget_exhausted', 'identificatore corto ammesso');
    p1a_assert($ledger->admit(1, 97, p1a_conf(800), P1A_TODAY, P1A_TODAY, 0) === 'budget_exhausted', 'unita nulle ammesse');
    p1a_assert($store->writes === 0, 'scrittura con ingresso non valido');
});

// ---------------------------------------------------------------------------
// Connettore locale e set di modelli (WordPressReviewProvider)
// ---------------------------------------------------------------------------

final class P1aRegistry
{
    public $has = true;
    public $class = P1A_CLASS;
    public $auth = 'presente';
    public $throws = false;

    public function hasProvider($id)
    {
        if ($this->throws) {
            throw new RuntimeException('registro simulato');
        }
        return $this->has;
    }

    public function getProviderClassName($id)
    {
        return $this->class;
    }

    public function getProviderRequestAuthentication($class)
    {
        return $this->auth;
    }
}

p1a_case('connettore locale: provider fuori set -> falso senza lookup nel registry', static function (): void {
    p1a_assert(WordPressReviewProvider::isConfiguredLocally('gemini') === false, 'gemini ammesso');
    p1a_assert($GLOBALS['p1a']['lookups'] === 0, 'registry consultato per provider fuori set');
});

p1a_case('connettore locale: classe esatta e autenticazione registrata -> vero', static function (): void {
    $GLOBALS['p1a']['registry'] = new P1aRegistry();
    p1a_assert(WordPressReviewProvider::isConfiguredLocally('openai') === true, 'connettore configurato non riconosciuto');
    p1a_assert($GLOBALS['p1a']['lookups'] === 1, 'lookup inatteso');
});

p1a_case('connettore locale: autenticazione assente, classe diversa, registry in errore -> falso', static function (): void {
    $registry = new P1aRegistry();
    $registry->auth = null;
    $GLOBALS['p1a']['registry'] = $registry;
    p1a_assert(WordPressReviewProvider::isConfiguredLocally('openai') === false, 'autenticazione assente accettata');
    $registry = new P1aRegistry();
    $registry->class = 'Other\\Class';
    $GLOBALS['p1a']['registry'] = $registry;
    p1a_assert(WordPressReviewProvider::isConfiguredLocally('openai') === false, 'classe diversa accettata');
    $registry = new P1aRegistry();
    $registry->throws = true;
    $GLOBALS['p1a']['registry'] = $registry;
    p1a_assert(WordPressReviewProvider::isConfiguredLocally('openai') === false, 'errore del registry accettato');
});

p1a_case('modello: limite 128 caratteri, nessuno spazio, nessun URL', static function (): void {
    p1a_assert(WordPressReviewProvider::isValidModel(str_repeat('a', 128)) === true, '128 caratteri rifiutati');
    p1a_assert(WordPressReviewProvider::isValidModel(str_repeat('a', 129)) === false, '129 caratteri ammessi');
    p1a_assert(WordPressReviewProvider::isValidModel('gpt-5.5') === true, 'ID valido rifiutato');
    p1a_assert(WordPressReviewProvider::isValidModel('gpt test') === false, 'spazio ammesso');
    p1a_assert(WordPressReviewProvider::isValidModel('https://x.example') === false, 'URL ammesso');
    p1a_assert(WordPressReviewProvider::isValidModel('') === false, 'modello vuoto ammesso');
});

// ---------------------------------------------------------------------------
// Impostazioni: valore salvato
// ---------------------------------------------------------------------------

p1a_case('impostazioni: attivazione con Core disponibile salva 1 e reindirizza senza contenuto AI', static function (): void {
    $env = new P1aEnv();
    $response = $env->controller()->settings('POST', ['seo_lab_nonce' => $env->nonce('seo_lab_ai_settings_1'), 'seolab_ai_enabled' => '1']);
    p1a_assert(p1a_status($response) === 302, 'attivazione non 302');
    p1a_assert($env->saved === '1', 'valore 1 non salvato');
});

// ---------------------------------------------------------------------------
// Esito
// ---------------------------------------------------------------------------

$total = count($p1a_pass) + count($p1a_fail);
echo 'P1.2 admin: ' . count($p1a_pass) . '/' . $total . " casi superati\n";
if ($p1a_fail !== []) {
    exit(1);
}

}
