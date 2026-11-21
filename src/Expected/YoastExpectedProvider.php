<?php

declare(strict_types=1);

namespace SeoLab\Expected;

use SeoLab\Contracts\ExpectedProvider;
use SeoLab\Contracts\ExpectedSnapshot;

/**
 * Atteso risolto da Yoast SEO tramite il solo indexable corrente del post (YoastIndexableReader), in sola lettura.
 * Non attiva Yoast, non forza gli indexable, non crea record, non legge tabelle o opzioni interne e non invoca la
 * REST API. Yoast assente, indexable mancante/obsoleto/non valido, versione non verificata, eccezioni e proprieta'
 * assenti o inattese restano isolate per famiglia e producono unavailable, con motivo in lastReason().
 * Configured resta unavailable. JSON-LD (S4): solo WebPage primaria da main_schema_id, in contesto admin, con schema
 * attivo, senza noindex, senza callback Yoast non ammesse nei hook di schema e con ID uguale al permalink.
 * Robots: direttive come array di stringhe (Yoast associativo o indicizzato); le direttive del solo Googlebot
 * hanno prefisso "googlebot: ".
 */
final class YoastExpectedProvider implements ExpectedProvider
{
    private const SOURCE = 'yoast:resolved';
    private const ABSOLUTE_HTTP = '#^https?://[^\s]+\z#';
    private const SCHEMA_DISABLED_CONDITIONAL = 'Yoast\\WP\\SEO\\Conditionals\\Schema_Disabled_Conditional';

    /** Hook di schema e presentazione front-end controllati prima di leggere main_schema_id (insieme chiuso). */
    private const WATCHED_HOOKS = [
        'wpseo_json_ld_output', 'wpseo_json_ld', 'wpseo_frontend_presenters', 'wpseo_frontend_presenter_classes',
        'wpseo_frontend_presentation', 'wpseo_dynamic_permalinks_enabled',
    ];
    private const WATCHED_PREFIXES = ['wpseo_schema_', 'wpseo_pre_schema_block_type_'];

    /** Callback native Yoast ammesse dalla guardia, per hook ed identita' esatta classe::metodo. */
    private const ALLOWED_CALLBACKS = [
        'wpseo_schema_webpage' => ['Yoast\\WP\\SEO\\Integrations\\Front_End\\Schema_Accessibility_Feature::maybe_add_accessibility_feature'],
        'wpseo_frontend_presenter_classes' => ['Yoast\\WP\\SEO\\Integrations\\Front_End_Integration::filter_robots_presenter'],
    ];

    /** @var YoastIndexableReader */
    private $reader;

    /** @var string */
    private $lastReason = 'not_called';

    /** @var string */
    private $lastJsonLdReason = 'not_called';

    /** @var string|null */
    private $lastGuardEvidence = null;

    /**
     * @param string|null $yoastVersion Versione Yoast da verificare; null: installazione corrente.
     */
    public function __construct(?string $yoastVersion = null)
    {
        $this->reader = new YoastIndexableReader($yoastVersion);
    }

    /**
     * Motivo dell'ultima risoluzione: not_eligible oppure un motivo di YoastIndexableReader.
     */
    public function lastReason(): string
    {
        return $this->lastReason;
    }

    /**
     * Motivo dell'ultimo atteso JSON-LD: ok oppure un motivo di contesto, schema, guardia o ID. not_called se mai risolto.
     */
    public function lastJsonLdReason(): string
    {
        return $this->lastJsonLdReason;
    }

    /**
     * Hook e callback che hanno fatto scattare la guardia dei callback (hook: classe::metodo), oppure null.
     */
    public function lastGuardEvidence(): ?string
    {
        return $this->lastGuardEvidence;
    }

    public function forPost(int $postId): ExpectedSnapshot
    {
        $resolved = [
            'canonical' => self::unavailable(),
            'robots' => self::unavailable(),
            'jsonld' => self::unavailable(),
        ];
        $this->lastJsonLdReason = 'not_resolved';
        $this->lastGuardEvidence = null;
        if (!self::eligible($postId)) {
            $this->lastReason = 'not_eligible';
        } else {
            $meta = $this->reader->presentationFor($postId);
            $this->lastReason = $this->reader->lastReason();
            if ($meta !== null) {
                $resolved['canonical'] = self::canonical($meta);
                $resolved['robots'] = self::robots($meta);
                $resolved['jsonld'] = $this->jsonLd($meta, $postId);
            }
        }
        $configured = [
            'canonical' => self::unavailable(),
            'robots' => self::unavailable(),
            'jsonld' => self::unavailable(),
        ];
        return new ExpectedSnapshot(['configured' => $configured, 'resolved' => $resolved]);
    }

    /**
     * Stessi criteri di idoneita' della capture S1: post o page pubblicati e non protetti da password.
     */
    private static function eligible(int $postId): bool
    {
        $post = get_post($postId);
        return is_object($post)
            && ($post->post_status ?? null) === 'publish'
            && in_array($post->post_type ?? null, ['post', 'page'], true)
            && ($post->post_password ?? '') === '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function canonical(object $meta): array
    {
        $value = self::read($meta, 'canonical');
        if (!is_string($value)) {
            return self::unavailable();
        }
        // Stringa vuota: nessun canonical atteso in modo esplicito (Yoast la restituisce con noindex).
        if ($value === '') {
            return self::available('');
        }
        if (preg_match(self::ABSOLUTE_HTTP, $value) !== 1) {
            return self::unavailable();
        }
        return self::available($value);
    }

    /**
     * @return array<string, mixed>
     */
    private static function robots(object $meta): array
    {
        $all = self::read($meta, 'robots');
        if (!is_array($all)) {
            return self::unavailable();
        }
        $directives = [];
        foreach ($all as $directive) {
            if (!is_string($directive)) {
                return self::unavailable();
            }
            $directive = trim($directive);
            if ($directive !== '') {
                $directives[] = $directive;
            }
        }
        $googlebot = self::read($meta, 'googlebot');
        if ($googlebot !== null) {
            if (!is_array($googlebot)) {
                return self::unavailable();
            }
            foreach ($googlebot as $directive) {
                if (!is_string($directive)) {
                    return self::unavailable();
                }
                $directive = trim($directive);
                if ($directive !== '') {
                    $directives[] = 'googlebot: ' . $directive;
                }
            }
        }
        return self::available($directives);
    }

    /**
     * JSON-LD atteso: WebPage primaria da main_schema_id. Ordine: contesto admin, noindex, schema attivo, registro dei
     * hook senza callback non ammesse, poi lettura dell'ID. Ogni esito negativo e' unavailable con motivo.
     *
     * @return array<string, mixed>
     */
    private function jsonLd(object $meta, int $postId): array
    {
        if (!function_exists('is_admin') || !is_admin()) {
            $this->lastJsonLdReason = 'context_not_admin';
            return self::unavailable();
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $this->lastJsonLdReason = 'context_rest';
            return self::unavailable();
        }
        $directives = self::read($meta, 'robots');
        if (is_array($directives)) {
            foreach ($directives as $directive) {
                if (is_string($directive) && trim($directive) === 'noindex') {
                    $this->lastJsonLdReason = 'noindex';
                    return self::unavailable();
                }
            }
        }
        $disabled = self::schemaDisabled();
        if ($disabled === null) {
            $this->lastJsonLdReason = 'conditional_unverifiable';
            return self::unavailable();
        }
        if ($disabled) {
            $this->lastJsonLdReason = 'schema_disabled';
            return self::unavailable();
        }
        $unexpected = self::unexpectedCallback();
        if ($unexpected !== null) {
            $this->lastGuardEvidence = $unexpected;
            $this->lastJsonLdReason = 'callback_unexpected';
            return self::unavailable();
        }

        // Lettura diretta: Meta::__isset guarda solo la presentazione, mentre __get ricade sul contesto (dove vive l'ID).
        $id = self::get($meta, 'main_schema_id');
        if (!is_string($id) || preg_match(self::ABSOLUTE_HTTP, $id) !== 1) {
            $this->lastJsonLdReason = 'schema_id_invalid';
            return self::unavailable();
        }
        $hash = strpos($id, '#');
        $base = $hash === false ? $id : substr($id, 0, $hash);
        $permalink = get_permalink($postId);
        $normalized = \SeoLab\Rules\CanonicalTargets::normalize($base);
        if (!is_string($permalink) || $normalized === null || $normalized !== \SeoLab\Rules\CanonicalTargets::normalize($permalink)) {
            $this->lastJsonLdReason = 'schema_id_mismatch';
            return self::unavailable();
        }
        $this->lastJsonLdReason = 'ok';
        return self::available(['expected_node_ids' => [$id]]);
    }

    /**
     * Schema disattivato per Yoast (conditional letto dal container). Null se non verificabile.
     */
    private static function schemaDisabled(): ?bool
    {
        try {
            $met = YoastSEO()->classes->get(self::SCHEMA_DISABLED_CONDITIONAL)->is_met();
        } catch (\Throwable $e) {
            return null;
        }
        return is_bool($met) ? $met : null;
    }

    /**
     * Primo callback registrato su un hook controllato che non e' ammesso per identita' esatta. Ispeziona $wp_filter
     * senza eseguire callback. Null se il registro e' conforme.
     */
    private static function unexpectedCallback(): ?string
    {
        $registry = $GLOBALS['wp_filter'] ?? [];
        if (!is_array($registry)) {
            return 'registro_hook_non_leggibile';
        }
        foreach ($registry as $hook => $container) {
            $hook = (string) $hook;
            if (!self::watched($hook) || !is_object($container) || !isset($container->callbacks) || !is_array($container->callbacks)) {
                continue;
            }
            foreach ($container->callbacks as $callbacks) {
                if (!is_array($callbacks)) {
                    return $hook . ': priorita non leggibile';
                }
                foreach ($callbacks as $callback) {
                    $id = is_array($callback) ? self::callbackId($callback['function'] ?? null) : null;
                    if ($id === null || !in_array($id, self::ALLOWED_CALLBACKS[$hook] ?? [], true)) {
                        return $hook . ': ' . ($id ?? 'callback non identificabile');
                    }
                }
            }
        }
        return null;
    }

    private static function watched(string $hook): bool
    {
        if (in_array($hook, self::WATCHED_HOOKS, true)) {
            return true;
        }
        foreach (self::WATCHED_PREFIXES as $prefix) {
            if (strpos($hook, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Identita' di un callback senza invocarlo: stringa di funzione, oppure classe::metodo. Closure: null.
     *
     * @param mixed $function
     */
    private static function callbackId($function): ?string
    {
        if (is_string($function)) {
            return $function;
        }
        if (is_array($function) && count($function) === 2 && is_string($function[1])) {
            $target = $function[0];
            $class = is_object($target) ? get_class($target) : (is_string($target) ? $target : null);
            return $class === null ? null : $class . '::' . $function[1];
        }
        return null;
    }

    /**
     * Valore di una proprieta' della Surface tramite __get, senza __isset. Null se la lettura fallisce.
     *
     * @return mixed
     */
    private static function get(object $meta, string $name)
    {
        try {
            return $meta->{$name};
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Legge una proprieta' della Surface (accesso dinamico con __isset/__get). Null se assente o se la lettura fallisce.
     *
     * @return mixed
     */
    private static function read(object $meta, string $name)
    {
        try {
            if (!isset($meta->{$name})) {
                return null;
            }
            return $meta->{$name};
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function available($value): array
    {
        return ['availability' => 'available', 'value' => $value, 'source' => self::SOURCE];
    }

    /**
     * @return array<string, mixed>
     */
    private static function unavailable(): array
    {
        return ['availability' => 'unavailable', 'value' => null, 'source' => null];
    }
}
