<?php

declare(strict_types=1);

namespace SeoLab\Admin;

use SeoLab\Contracts\ReportStorage;
use SeoLab\Diagnosis\ScanRefused;
use SeoLab\Diagnosis\ScanService;

/**
 * Azioni admin Scan, Verify ed Export senza effetti WordPress diretti. Ordine dei controlli: metodo, capability,
 * nonce (legato a sito, azione e UUID), forma dell'input, schema, eleggibilita e cooldown dentro lo scan, cattura,
 * salvataggio, potatura. Ogni errore e' un codice stabile; il chiamante traduce in risposta HTTP o messaggio.
 */
final class ReportActions
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';
    private const POST_ID_PATTERN = '/^[1-9][0-9]{0,18}\z/';

    /** @var string */
    private $siteKey;

    /** @var callable(): bool */
    private $can;

    /** @var callable(string, string): bool */
    private $verifyNonce;

    /** @var ReportStorage */
    private $storage;

    /** @var ScanService */
    private $scan;

    /** @var string */
    private $pageUrl;

    /**
     * @param string                       $siteKey     identificatore del sito (blog ID), parte delle azioni nonce
     * @param callable(): bool             $can         capability manage_options del sito corrente
     * @param callable(string, string): bool $verifyNonce (azione, valore) -> valido
     * @param string                       $pageUrl     URL assoluto della pagina admin, costruito dal chiamante
     */
    public function __construct(string $siteKey, callable $can, callable $verifyNonce, ReportStorage $storage, ScanService $scan, string $pageUrl)
    {
        $this->siteKey = $siteKey;
        $this->can = $can;
        $this->verifyNonce = $verifyNonce;
        $this->storage = $storage;
        $this->scan = $scan;
        $this->pageUrl = $pageUrl;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function scan(string $method, array $request): array
    {
        if ($method !== 'POST') {
            return self::error('method_not_allowed');
        }
        if (!($this->can)()) {
            return self::error('forbidden');
        }
        if (!$this->nonceOk('seo_lab_scan_' . $this->siteKey, $request)) {
            return self::error('bad_nonce');
        }
        $postId = self::parsePostId($request['post_id'] ?? null);
        if ($postId === null) {
            return self::error('bad_request');
        }
        if (!$this->storage->ensureSchema()) {
            return self::error('storage_unavailable');
        }
        return $this->run($postId, null);
    }

    /**
     * Verify usa il post ID del report base trovato nell'archivio del sito: input di post o URL del client ignorati.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function verify(string $method, array $request): array
    {
        if ($method !== 'POST') {
            return self::error('method_not_allowed');
        }
        if (!($this->can)()) {
            return self::error('forbidden');
        }
        $uuid = self::parseUuid($request['report'] ?? null);
        if ($uuid === null) {
            return self::error('bad_request');
        }
        if (!$this->nonceOk('seo_lab_verify_' . $this->siteKey . '_' . $uuid, $request)) {
            return self::error('bad_nonce');
        }
        if (!$this->storage->ensureSchema()) {
            return self::error('storage_unavailable');
        }
        $base = $this->storage->find($uuid);
        if ($base === null) {
            return self::error('not_found');
        }
        return $this->run($base->postId(), $uuid);
    }

    /**
     * Export del report come payload con allowlist. Solo POST: la risposta e' un download.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function export(string $method, array $request): array
    {
        if ($method !== 'POST') {
            return self::error('method_not_allowed');
        }
        if (!($this->can)()) {
            return self::error('forbidden');
        }
        $uuid = self::parseUuid($request['report'] ?? null);
        if ($uuid === null) {
            return self::error('bad_request');
        }
        if (!$this->nonceOk('seo_lab_export_' . $this->siteKey . '_' . $uuid, $request)) {
            return self::error('bad_nonce');
        }
        $report = $this->storage->find($uuid);
        if ($report === null) {
            return self::error('not_found');
        }
        return [
            'ok' => true,
            'filename' => 'seo-lab-report-' . $uuid . '.json',
            'payload' => ExportProjection::fromReport($report),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function run(int $postId, ?string $baseScanId): array
    {
        try {
            $report = $this->scan->scan($postId);
        } catch (ScanRefused $e) {
            return self::error($e->reason());
        }
        try {
            $this->storage->save($report);
        } catch (\Throwable $e) {
            return self::error('storage_error');
        }
        $keep = [$report->scanId()];
        if ($baseScanId !== null) {
            $keep[] = $baseScanId;
        }
        try {
            $this->storage->prune($postId, $keep);
        } catch (\Throwable $e) {
            // Il report e' gia' salvato: una potatura fallita lascia il cap sforato fino alla prossima scansione.
        }
        $redirect = $this->pageUrl . '&report=' . $report->scanId();
        if ($baseScanId !== null) {
            $redirect .= '&base=' . $baseScanId;
        }
        return ['ok' => true, 'report' => $report->scanId(), 'redirect' => $redirect];
    }

    /**
     * @param array<string, mixed> $request
     */
    private function nonceOk(string $action, array $request): bool
    {
        $nonce = $request['seo_lab_nonce'] ?? null;
        return is_string($nonce) && $nonce !== '' && ($this->verifyNonce)($action, $nonce);
    }

    /**
     * ID decimale positivo come stringa, senza coercizioni, array o overflow.
     *
     * @param mixed $raw
     */
    public static function parsePostId($raw): ?int
    {
        if (!is_string($raw) || preg_match(self::POST_ID_PATTERN, $raw) !== 1) {
            return null;
        }
        $int = (int) $raw;
        return (string) $int === $raw ? $int : null;
    }

    /**
     * @param mixed $raw
     */
    public static function parseUuid($raw): ?string
    {
        return is_string($raw) && preg_match(self::UUID_PATTERN, $raw) === 1 ? $raw : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function error(string $code): array
    {
        return ['ok' => false, 'error' => $code];
    }
}
