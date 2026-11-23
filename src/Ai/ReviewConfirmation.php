<?php

declare(strict_types=1);

namespace SeoLab\Ai;

use SeoLab\Contracts\ScanReport;

/**
 * Tupla firmata della revisione AI privata (P1.2). L'anteprima e la conferma ricalcolano la stessa tupla: blog ID,
 * utente, UUID, digest del rapporto, provider, modello, lingua, versione del prompt, digest di system e data, cap di
 * output, istante ed identificatore casuale. Il digest entra nell'azione del nonce di contesto: un cambio di un solo
 * campo invalida la firma.
 */
final class ReviewConfirmation
{
    public const TTL_SECONDS = 300;
    public const ID_PATTERN = '/\A[0-9a-f]{32}\z/';

    /**
     * Digest SHA-256 del rapporto serializzato. Stessa forma usata in anteprima e in conferma.
     */
    public static function reportDigest(ScanReport $report): string
    {
        return hash('sha256', serialize($report->toArray()));
    }

    /**
     * Digest della tupla canonica. Null se la codifica non e' deterministica: il chiamante blocca l'invio.
     *
     * @param array<string, mixed> $tuple
     */
    public static function digest(array $tuple): ?string
    {
        ksort($tuple);
        $json = json_encode($tuple, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? hash('sha256', $json) : null;
    }

    /**
     * Tupla firmata. Ogni chiave e' un campo che la conferma rilegge e ricalcola dal server.
     *
     * @param array{system: string, data: string} $prompt
     * @return array<string, mixed>
     */
    public static function tuple(
        string $blogId,
        int $userId,
        string $reportId,
        string $reportDigest,
        string $provider,
        string $model,
        string $language,
        array $prompt,
        int $issuedAt,
        string $confirmationId
    ): array {
        return [
            'blog_id' => $blogId,
            'user_id' => $userId,
            'report_id' => $reportId,
            'report_digest' => $reportDigest,
            'provider' => $provider,
            'model' => $model,
            'language' => $language,
            'prompt_version' => ReviewRequest::PROMPT_VERSION,
            'system_digest' => hash('sha256', $prompt['system']),
            'data_digest' => hash('sha256', $prompt['data']),
            'max_output_tokens' => WordPressReviewProvider::MAX_OUTPUT_TOKENS,
            'issued_at' => $issuedAt,
            'confirmation_id' => $confirmationId,
        ];
    }

    /**
     * Azione del nonce di fase: legata a fase, sito e UUID (il nonce WordPress aggiunge utente e sessione).
     */
    public static function phaseAction(string $phase, string $blogId, string $reportId): string
    {
        return 'seo_lab_ai_' . $phase . '_' . $blogId . '_' . $reportId;
    }

    /**
     * Azione del nonce di contesto: include il digest della tupla firmata.
     */
    public static function contextAction(string $digest): string
    {
        return 'seo_lab_ai_ctx_' . $digest;
    }
}
