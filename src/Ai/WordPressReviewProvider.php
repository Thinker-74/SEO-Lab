<?php

declare(strict_types=1);

namespace SeoLab\Ai;

use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Adapter di revisione AI opzionale (P1.1) per OpenAI e Anthropic tramite i connettori WordPress Core. Ordine fisso:
 * gate e ingresso, registry e autenticazione senza egress, ammissione una sola volta, risoluzione del modello sotto
 * guardia (una GET di catalogo al massimo), generazione sotto guardia (un solo POST). Nessun retry, nessun provider
 * alternativo, nessun testo di errore o corpo grezzo nell'esito.
 */
final class WordPressReviewProvider implements ReviewProvider
{
    private const CONNECTORS = [
        'openai' => [
            'class' => 'WordPress\\OpenAiAiProvider\\Provider\\OpenAiProvider',
            'host' => 'api.openai.com',
            'path' => '/v1/responses',
            'candidates' => true,
            'truncation' => false,
        ],
        'anthropic' => [
            'class' => 'WordPress\\AnthropicAiProvider\\Provider\\AnthropicProvider',
            'host' => 'api.anthropic.com',
            'path' => '/v1/messages',
            'candidates' => false,
            'truncation' => true,
        ],
    ];
    private const MODELS_PATH = '/v1/models';
    private const REASONING_NONE_MODEL = 'gpt-6-luna';
    private const MODEL_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/';
    public const MAX_OUTPUT_TOKENS = 768;
    private const ADMISSION_REFUSALS = ['rate_limited', 'budget_exhausted'];

    /** @var bool */
    private $enabled;

    /** @var string */
    private $provider;

    /** @var string */
    private $model;

    /** @var callable */
    private $admit;

    /** @var array<string, int>|null */
    private $lastUsage = null;

    /**
     * @param callable $admit Ammissione della riserva: null se accettata, altrimenti rate_limited o budget_exhausted.
     */
    public function __construct(bool $enabled, string $provider, string $model, callable $admit)
    {
        $this->enabled = $enabled;
        $this->provider = $provider;
        $this->model = $model;
        $this->admit = $admit;
    }

    public function review(ReviewRequest $request): ReviewResult
    {
        $this->lastUsage = null;

        $status = WordPressAiRuntime::status($this->enabled);
        if ($status !== 'core_available') {
            return ReviewResult::failure($status);
        }
        if (!isset(self::CONNECTORS[$this->provider]) || !self::isValidModel($this->model)) {
            return ReviewResult::failure('invalid_input');
        }
        if (!class_exists('WordPress\\AiClient\\AiClient') || !method_exists('WordPress\\AiClient\\AiClient', 'defaultRegistry')) {
            return ReviewResult::failure('unsupported_runtime');
        }

        $connector = self::CONNECTORS[$this->provider];
        $registry = \WordPress\AiClient\AiClient::defaultRegistry();
        if (!self::isConnected($registry, $this->provider, $connector['class'])) {
            return ReviewResult::failure('unconfigured');
        }

        $verdict = $this->admission();
        if ($verdict !== null) {
            return ReviewResult::failure($verdict);
        }

        $scope = new WordPressReviewHttpScope($connector['host'], static function (): int {
            return hrtime(true);
        }, $connector['truncation']);
        $scope->enter();
        try {
            return $this->execute($request, $connector, $registry, $scope);
        } catch (\Throwable $e) {
            return $this->failed($scope, 'provider_error');
        } finally {
            $scope->leave();
        }
    }

    /**
     * Conteggi del tentativo piu' recente, solo se non negativi e disponibili. Null se assenti o dopo un fallimento.
     *
     * @return array<string, int>|null
     */
    public function lastUsage(): ?array
    {
        return $this->lastUsage;
    }

    /**
     * Provider ammesso e connettore configurato localmente nel registry Core. Nessuna chiamata di rete, nessuna
     * risoluzione di modelli: il chiamante decide prima se l'opt-in e' attivo.
     */
    public static function isConfiguredLocally(string $provider): bool
    {
        if (!isset(self::CONNECTORS[$provider])) {
            return false;
        }
        if (!class_exists('WordPress\\AiClient\\AiClient') || !method_exists('WordPress\\AiClient\\AiClient', 'defaultRegistry')) {
            return false;
        }
        $registry = \WordPress\AiClient\AiClient::defaultRegistry();
        return self::isConnected($registry, $provider, self::CONNECTORS[$provider]['class']);
    }

    public static function isValidModel(string $model): bool
    {
        return preg_match(self::MODEL_PATTERN, $model) === 1;
    }

    /**
     * Profilo di ragionamento per la coppia esatta: solo OpenAI con gpt-6-luna usa effort none, ogni altra coppia
     * resta senza opzione. Usato dall'adapter, dall'anteprima e dalla conferma.
     */
    public static function reasoningEffort(string $provider, string $model): ?string
    {
        return $provider === 'openai' && $model === self::REASONING_NONE_MODEL ? 'none' : null;
    }

    /**
     * Presenza del connettore e della sua autenticazione nel registry Core. Nessuna chiamata di rete.
     *
     * @param object $registry
     */
    private static function isConnected($registry, string $provider, string $class): bool
    {
        try {
            return $registry->hasProvider($provider)
                && $registry->getProviderClassName($provider) === $class
                && $registry->getProviderRequestAuthentication($class) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Ammissione chiamata una sola volta per richiesta eleggibile. Valore sconosciuto o eccezione: rifiuto.
     */
    private function admission(): ?string
    {
        try {
            $verdict = ($this->admit)();
        } catch (\Throwable $e) {
            return 'budget_exhausted';
        }
        if ($verdict === null) {
            return null;
        }
        return in_array($verdict, self::ADMISSION_REFUSALS, true) ? $verdict : 'budget_exhausted';
    }

    /**
     * @param array{class: string, host: string, path: string, candidates: bool, truncation: bool} $connector
     * @param object                                                             $registry
     */
    private function execute(ReviewRequest $request, array $connector, $registry, WordPressReviewHttpScope $scope): ReviewResult
    {
        $scope->allow('GET', self::MODELS_PATH);
        try {
            $instance = $registry->getProviderModel($connector['class'], $this->model);
            $metadata = $instance->metadata();
            $providerId = $instance->providerMetadata()->getId();
        } catch (\InvalidArgumentException $e) {
            return $this->failed($scope, 'model_unavailable');
        }
        if ($metadata->getId() !== $this->model || $providerId !== $this->provider || !self::isTextCapable($instance, $metadata)) {
            return $this->failed($scope, 'model_unavailable');
        }
        if ($scope->expired()) {
            return $this->failed($scope, 'timeout');
        }

        $scope->allow('POST', $connector['path']);
        $prompt = ReviewPrompt::fromRequest($request);
        $builder = wp_ai_client_prompt($prompt['data'])
            ->using_system_instruction($prompt['system'])
            ->using_model($instance)
            ->using_max_tokens(self::MAX_OUTPUT_TOKENS)
            ->using_request_options(RequestOptions::fromArray([
                'timeout' => $scope->residualSeconds(),
                'maxRedirects' => 0,
            ]));
        if ($connector['candidates']) {
            $builder = $builder->using_candidate_count(1);
        }
        $effort = self::reasoningEffort($this->provider, $this->model);
        if ($effort !== null) {
            $builder = $builder->using_model_config(ModelConfig::fromArray(['customOptions' => ['reasoning' => ['effort' => $effort]]]));
        }

        return $this->interpret($builder->generate_text_result(), $request, $scope);
    }

    /**
     * @param mixed $result
     */
    private function interpret($result, ReviewRequest $request, WordPressReviewHttpScope $scope): ReviewResult
    {
        if (is_wp_error($result)) {
            return $this->fromError($result, $scope);
        }
        if (!$result instanceof GenerativeAiResult) {
            return $this->failed($scope, 'invalid_response');
        }
        if ($scope->expired()) {
            return $this->failed($scope, 'timeout');
        }
        // Il segnale di troncamento precede decodifica del testo e usage: l'esito non porta item ne' conteggi.
        if ($scope->truncated()) {
            return $this->failed($scope, 'output_truncated');
        }
        // OpenAI: solo la terminazione stop del primo candidato vale, a prescindere dal testo (incomplete, content_filter).
        if ($this->provider === 'openai' && !self::finishedWithStop($result)) {
            return ReviewResult::failure('invalid_response');
        }
        if ($result->getProviderMetadata()->getId() !== $this->provider || $result->getModelMetadata()->getId() !== $this->model) {
            return ReviewResult::failure('invalid_response');
        }

        $this->lastUsage = self::usageOf($result);
        try {
            $text = $result->toText();
        } catch (\Throwable $e) {
            return ReviewResult::failure('invalid_response');
        }
        return ReviewResult::fromJson($text, $request);
    }

    /**
     * Mappa solo codici e status strutturati. Il motivo della guardia prevale: il trasporto puo' perdere il codice.
     *
     * @param \WP_Error $error
     */
    private function fromError($error, WordPressReviewHttpScope $scope): ReviewResult
    {
        $scoped = $this->scopeFailure($scope);
        if ($scoped !== null) {
            return $scoped;
        }
        if ($scope->truncated()) {
            return ReviewResult::failure('output_truncated');
        }
        $code = $error->get_error_code();
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) && is_int($data['status']) ? $data['status'] : 0;

        if ($code === 'prompt_token_limit_reached' && $this->provider === 'openai') {
            return ReviewResult::failure('output_truncated');
        }
        if ($code === 'prompt_prevented') {
            return ReviewResult::failure('core_disabled');
        }
        if ($status === 408 || $status === 504) {
            return ReviewResult::failure('timeout');
        }
        if ($status === 429) {
            return ReviewResult::failure('rate_limited');
        }
        if ($status === 401) {
            return ReviewResult::failure('unconfigured');
        }
        return ReviewResult::failure('provider_error');
    }

    /**
     * Precedenza: timeout e rifiuti della guardia, poi il troncamento segnalato dal trasporto, poi il codice dato.
     */
    private function failed(WordPressReviewHttpScope $scope, string $code): ReviewResult
    {
        return $this->scopeFailure($scope) ?? ReviewResult::failure($scope->truncated() ? 'output_truncated' : $code);
    }

    /**
     * Primo candidato con terminazione stop. Candidato assente, altra terminazione o eccezione: falso.
     *
     * @param mixed $result
     */
    private static function finishedWithStop($result): bool
    {
        try {
            $candidates = $result->getCandidates();
            $first = $candidates[0] ?? null;
            return $first !== null && $first->getFinishReason()->isStop();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function scopeFailure(WordPressReviewHttpScope $scope): ?ReviewResult
    {
        if ($scope->reason() === WordPressReviewHttpScope::REASON_TIMEOUT || $scope->expired()) {
            return ReviewResult::failure('timeout');
        }
        if ($scope->reason() === WordPressReviewHttpScope::REASON_SCOPE) {
            return ReviewResult::failure('provider_error');
        }
        return null;
    }

    /**
     * @return array<string, int>|null
     */
    private static function usageOf($result): ?array
    {
        try {
            $usage = $result->getTokenUsage();
            $input = $usage->getPromptTokens();
            $output = $usage->getCompletionTokens();
        } catch (\Throwable $e) {
            return null;
        }
        $counts = [];
        if (is_int($input) && $input >= 0) {
            $counts['input_tokens'] = $input;
        }
        if (is_int($output) && $output >= 0) {
            $counts['output_tokens'] = $output;
        }
        return $counts === [] ? null : $counts;
    }

    /**
     * @param mixed $instance
     * @param mixed $metadata
     */
    private static function isTextCapable($instance, $metadata): bool
    {
        if (!$instance instanceof TextGenerationModelInterface) {
            return false;
        }
        foreach ($metadata->getSupportedCapabilities() as $capability) {
            if ($capability->isTextGeneration()) {
                return true;
            }
        }
        return false;
    }
}
