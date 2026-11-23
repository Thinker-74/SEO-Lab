<?php
/**
 * Prova S4 P1.2 (solo sandbox, mai nello ZIP): connettore OpenAI sintetico registrato nel registry Core reale, con
 * modello doppio "p1-fixture" che non apre rete. tests/s4-wordpress.sh --p1-ai copia questo file nei mu-plugins della
 * sola sandbox dopo la prova con connettore assente. Ogni chiamata del modello aggiunge una riga con l'hash del payload
 * in wp-content/s4-p1-calls.log; ogni richiesta HTTP verso i provider viene bloccata e registrata in
 * wp-content/s4-p1-egress.log.
 */

declare(strict_types=1);

namespace WordPress\OpenAiAiProvider\Provider {

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

final class OpenAiProvider extends AbstractProvider
{
    protected static function createProviderMetadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            'openai',
            'OpenAI (prova S4)',
            ProviderTypeEnum::cloud(),
            null,
            RequestAuthenticationMethod::apiKey()
        );
    }

    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new S4FixtureAvailability();
    }

    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new S4FixtureDirectory();
    }

    protected static function createModel(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata): ModelInterface
    {
        return new S4FixtureTextModel($modelMetadata, $providerMetadata);
    }
}

final class S4FixtureAvailability implements ProviderAvailabilityInterface
{
    public function isConfigured(): bool
    {
        return true;
    }
}

final class S4FixtureDirectory implements ModelMetadataDirectoryInterface
{
    public function listModelMetadata(): array
    {
        return [self::metadata()];
    }

    public function hasModelMetadata(string $modelId): bool
    {
        return $modelId === 'p1-fixture';
    }

    public function getModelMetadata(string $modelId): ModelMetadata
    {
        if ($modelId !== 'p1-fixture') {
            throw new \InvalidArgumentException('modello non presente nel catalogo di prova');
        }
        return self::metadata();
    }

    private static function metadata(): ModelMetadata
    {
        return new ModelMetadata(
            'p1-fixture',
            'P1 fixture',
            [CapabilityEnum::textGeneration()],
            [
                new SupportedOption(OptionEnum::systemInstruction()),
                new SupportedOption(OptionEnum::maxTokens()),
                new SupportedOption(OptionEnum::candidateCount()),
            ]
        );
    }
}

/**
 * Modello doppio: nessuna rete. Legge il payload dal messaggio utente e restituisce una risposta di prova valida
 * per le ref della richiesta (regola ed evidenze), nel formato review-1.
 */
final class S4FixtureTextModel implements ModelInterface, TextGenerationModelInterface
{
    /** @var ModelMetadata */
    private $metadata;
    /** @var ProviderMetadata */
    private $providerMetadata;
    /** @var ModelConfig */
    private $config;

    public function __construct(ModelMetadata $metadata, ProviderMetadata $providerMetadata)
    {
        $this->metadata = $metadata;
        $this->providerMetadata = $providerMetadata;
        $this->config = new ModelConfig();
    }

    public function metadata(): ModelMetadata
    {
        return $this->metadata;
    }

    public function providerMetadata(): ProviderMetadata
    {
        return $this->providerMetadata;
    }

    public function setConfig(ModelConfig $config): void
    {
        $this->config = $config;
    }

    public function getConfig(): ModelConfig
    {
        return $this->config;
    }

    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $payload = null;
        foreach ($prompt as $message) {
            foreach ($message->getParts() as $part) {
                $text = $part->getText();
                if (is_string($text) && strpos($text, '"findings"') !== false) {
                    $payload = json_decode($text, true);
                }
            }
        }
        if (!is_array($payload) || !isset($payload['findings']) || !is_array($payload['findings']) || $payload['findings'] === []) {
            throw new \RuntimeException('payload di prova assente');
        }
        file_put_contents(ABSPATH . 'wp-content/s4-p1-calls.log', hash('sha256', (string) json_encode($payload)) . "\n", FILE_APPEND);

        $finding = $payload['findings'][0];
        $rule = (string) $finding['rule_id'];
        $evidenceRef = isset($finding['evidence'][0]['ref']) ? (string) $finding['evidence'][0]['ref'] : $rule;
        $items = [
            ['kind' => 'explanation', 'text' => 'Spiegazione prudente: la regola ' . $rule . ' segnala una differenza da verificare.', 'refs' => [$rule]],
            ['kind' => 'hypothesis', 'text' => 'Ipotesi: il dato osservato potrebbe provenire da un altro componente.', 'refs' => [$evidenceRef]],
            ['kind' => 'suggested_test', 'text' => 'Confronta il valore atteso con l\'output pubblico della pagina.', 'refs' => [$rule]],
        ];
        $json = (string) json_encode(['schema_version' => '1', 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $candidate = new Candidate(new Message(MessageRoleEnum::model(), [new MessagePart($json)]), FinishReasonEnum::stop());
        return new GenerativeAiResult(
            'p1-fixture-' . bin2hex(random_bytes(4)),
            [$candidate],
            new TokenUsage(120, 80, 200),
            $this->providerMetadata,
            $this->metadata
        );
    }
}

}

namespace {

if (!defined('ABSPATH')) {
    exit;
}

add_filter('pre_http_request', static function ($pre, $args, $url) {
    if ($pre === false && is_string($url) && preg_match('#^https://api\.(openai|anthropic)\.com/#', $url) === 1) {
        file_put_contents(ABSPATH . 'wp-content/s4-p1-egress.log', "egress\n", FILE_APPEND);
        return new WP_Error('s4_no_egress', 'Prova S4: nessuna rete verso i provider.');
    }
    return $pre;
}, 1, 3);

add_action('init', static function (): void {
    // Un errore di registrazione non deve far cadere il sito: viene scritto nel log della sandbox e la prova fallisce.
    try {
        if (!class_exists('WordPress\\AiClient\\AiClient')) {
            throw new \RuntimeException('AiClient assente');
        }
        $registry = \WordPress\AiClient\AiClient::defaultRegistry();
        if (!$registry->hasProvider('openai')) {
            $registry->registerProvider(\WordPress\OpenAiAiProvider\Provider\OpenAiProvider::class);
        }
        $registry->setProviderRequestAuthentication(
            'openai',
            new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication('sk-p1-fixture-senza-rete')
        );
    } catch (\Throwable $e) {
        file_put_contents(ABSPATH . 'wp-content/s4-p1-setup-error.log', get_class($e) . ': ' . $e->getMessage() . "\n", FILE_APPEND);
    }
}, 20);

}
