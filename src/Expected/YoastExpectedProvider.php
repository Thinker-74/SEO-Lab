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
 * Configured resta unavailable in S2; JSON-LD resta unavailable fino a G-004.
 * Robots: direttive come array di stringhe (Yoast associativo o indicizzato); le direttive del solo Googlebot
 * hanno prefisso "googlebot: ".
 */
final class YoastExpectedProvider implements ExpectedProvider
{
    private const SOURCE = 'yoast:resolved';
    private const ABSOLUTE_HTTP = '#^https?://[^\s]+\z#';

    /** @var YoastIndexableReader */
    private $reader;

    /** @var string */
    private $lastReason = 'not_called';

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

    public function forPost(int $postId): ExpectedSnapshot
    {
        $resolved = [
            'canonical' => self::unavailable(),
            'robots' => self::unavailable(),
            'jsonld' => self::unavailable(),
        ];
        if (!self::eligible($postId)) {
            $this->lastReason = 'not_eligible';
        } else {
            $meta = $this->reader->presentationFor($postId);
            $this->lastReason = $this->reader->lastReason();
            if ($meta !== null) {
                $resolved['canonical'] = self::canonical($meta);
                $resolved['robots'] = self::robots($meta);
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
