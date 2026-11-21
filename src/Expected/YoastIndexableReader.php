<?php

declare(strict_types=1);

namespace SeoLab\Expected;

/**
 * Adattatore interno, in sola lettura, per Yoast SEO 28.9: legge il record indexable corrente di un post e ne
 * ricava la presentazione. Non crea, non aggiorna e non ripara indexable: il lookup usa il repository senza
 * auto-create (non si passa da find_by_id_and_type) e la verifica di versione non esegue upgrade.
 * Ogni uscita negativa ha un motivo distinto e non chiama la presentazione.
 */
final class YoastIndexableReader
{
    /** Versioni di Yoast con questa lettura verificata. Altre versioni: nessun fallback alla lettura del post. */
    public const VERIFIED_VERSIONS = ['28.9'];

    public const REASON_OK = 'ok';
    public const REASON_YOAST_ABSENT = 'yoast_absent';
    public const REASON_VERSION_UNVERIFIED = 'yoast_version_unverified';
    public const REASON_INDEXABLE_MISSING = 'indexable_missing';
    public const REASON_INDEXABLE_INVALID = 'indexable_invalid';
    public const REASON_INDEXABLE_OBSOLETE = 'indexable_obsolete';
    public const REASON_META_UNAVAILABLE = 'meta_unavailable';
    public const REASON_API_ERROR = 'yoast_api_error';

    private const REPOSITORY = 'Yoast\\WP\\SEO\\Repositories\\Indexable_Repository';
    private const VERSION_MANAGER = 'Yoast\\WP\\SEO\\Services\\Indexables\\Indexable_Version_Manager';
    private const INDEXABLE = 'Yoast\\WP\\SEO\\Models\\Indexable';
    private const PAGE_TYPE = 'Post_Type';

    /** @var string|null */
    private $yoastVersion;

    /** @var string */
    private $lastReason = 'not_called';

    /**
     * @param string|null $yoastVersion Versione da verificare; null: costante WPSEO_VERSION dell'installazione.
     */
    public function __construct(?string $yoastVersion = null)
    {
        $this->yoastVersion = $yoastVersion;
    }

    public function lastReason(): string
    {
        return $this->lastReason;
    }

    /**
     * @return object|null Presentazione Yoast (Surfaces Meta) oppure null, con motivo in lastReason().
     */
    public function presentationFor(int $postId): ?object
    {
        $this->lastReason = self::REASON_YOAST_ABSENT;
        if (!function_exists('YoastSEO')) {
            return null;
        }
        $version = $this->yoastVersion ?? (defined('WPSEO_VERSION') ? (string) WPSEO_VERSION : null);
        if ($version === null || !in_array($version, self::VERIFIED_VERSIONS, true)) {
            $this->lastReason = self::REASON_VERSION_UNVERIFIED;
            return null;
        }
        try {
            $classes = YoastSEO()->classes;
            $indexable = $classes->get(self::REPOSITORY)
                ->query()
                ->where('object_id', $postId)
                ->where('object_type', 'post')
                ->find_one();
            if ($indexable === false || $indexable === null) {
                $this->lastReason = self::REASON_INDEXABLE_MISSING;
                return null;
            }
            if (!is_a($indexable, self::INDEXABLE)
                || (int) $indexable->object_id !== $postId
                || (string) $indexable->object_type !== 'post'
            ) {
                $this->lastReason = self::REASON_INDEXABLE_INVALID;
                return null;
            }
            if ($classes->get(self::VERSION_MANAGER)->indexable_needs_upgrade($indexable) !== false) {
                $this->lastReason = self::REASON_INDEXABLE_OBSOLETE;
                return null;
            }
            $meta = YoastSEO()->meta->for_indexable($indexable, self::PAGE_TYPE);
        } catch (\Throwable $e) {
            $this->lastReason = self::REASON_API_ERROR;
            return null;
        }
        if (!is_object($meta)) {
            $this->lastReason = self::REASON_META_UNAVAILABLE;
            return null;
        }
        $this->lastReason = self::REASON_OK;
        return $meta;
    }
}
