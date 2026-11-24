<?php

declare(strict_types=1);

namespace SeoLab\Admin;

use SeoLab\Ai\ReviewConfirmation;
use SeoLab\Ai\ReviewPrompt;
use SeoLab\Ai\ReviewProvider;
use SeoLab\Ai\ReviewRequest;
use SeoLab\Ai\WordPressReviewProvider;
use SeoLab\Contracts\ReportStorage;
use SeoLab\Contracts\ScanReport;
use SeoLab\Storage\WordPressAiLedger;

/**
 * Azioni admin della revisione AI privata (P1.2): impostazioni, anteprima e conferma singola. Ogni handler restituisce
 * una risposta (stato, corpo HTML o redirect) e non emette output: il chiamante WordPress la invia. Ordine: metodo,
 * contesto del sito, capability, tipi scalari e forma dei campi, sessione ed eta' della conferma, nonce di fase
 * legato a sito/utente/UUID, archivio, opt-in, runtime, eleggibilita', nonce di contesto ricalcolato, riserva.
 * Un fallimento prima della riserva non consuma la conferma; dopo la riserva la conferma e' consumata.
 */
final class AiReviewActions
{
    public const OPTION = 'seolab_ai_enabled';

    /** @var string */
    private $blogId;
    /** @var callable(): bool */
    private $siteOk;
    /** @var callable(): bool */
    private $can;
    /** @var callable(): int */
    private $userId;
    /** @var callable(string, string): bool */
    private $verifyNonce;
    /** @var callable(string): string */
    private $createNonce;
    /** @var callable(): int */
    private $now;
    /** @var callable(): string */
    private $randomHex;
    /** @var callable(): bool */
    private $optIn;
    /** @var callable(string): bool */
    private $saveOptIn;
    /** @var callable(bool): string */
    private $runtime;
    /** @var ReportStorage */
    private $archive;
    /** @var callable(int): bool */
    private $eligible;
    /** @var callable(string): bool */
    private $isConfigured;
    /** @var WordPressAiLedger */
    private $ledger;
    /** @var callable(bool, string, string, callable): ReviewProvider */
    private $providerFactory;
    /** @var string */
    private $postUrl;
    /** @var string */
    private $settingsUrl;
    /** @var string */
    private $reportPageUrl;

    private const PROVIDERS = ['openai', 'anthropic'];
    private const LANGUAGES = ['it', 'en'];
    private const ISSUED_PATTERN = '/\A[0-9]{1,12}\z/';
    private const FIELD_MAX = 128;
    private const RUNTIME_READY = 'core_available';

    /**
     * @param callable(): bool                                       $siteOk          nessun contesto di rete o di blog commutato
     * @param callable(): bool                                       $can             capability manage_options
     * @param callable(): int                                        $userId          utente corrente, 0 se assente
     * @param callable(string, string): bool                         $verifyNonce     (azione, valore)
     * @param callable(string): string                               $createNonce     (azione) -> valore
     * @param callable(): int                                        $now             istante Unix
     * @param callable(): string                                     $randomHex       32 caratteri esadecimali casuali
     * @param callable(): bool                                       $optIn           opt-in del sito, letto solo qui
     * @param callable(string): bool                                 $saveOptIn       salva '1' o '0'
     * @param callable(bool): string                                 $runtime         WordPressAiRuntime::status
     * @param callable(int): bool                                    $eligible        idoneita' del contenuto
     * @param callable(string): bool                                 $isConfigured    connettore locale configurato
     * @param callable(bool, string, string, callable): ReviewProvider $providerFactory
     */
    public function __construct(
        string $blogId,
        callable $siteOk,
        callable $can,
        callable $userId,
        callable $verifyNonce,
        callable $createNonce,
        callable $now,
        callable $randomHex,
        callable $optIn,
        callable $saveOptIn,
        callable $runtime,
        ReportStorage $archive,
        callable $eligible,
        callable $isConfigured,
        WordPressAiLedger $ledger,
        callable $providerFactory,
        string $postUrl,
        string $settingsUrl,
        string $reportPageUrl
    ) {
        $this->blogId = $blogId;
        $this->siteOk = $siteOk;
        $this->can = $can;
        $this->userId = $userId;
        $this->verifyNonce = $verifyNonce;
        $this->createNonce = $createNonce;
        $this->now = $now;
        $this->randomHex = $randomHex;
        $this->optIn = $optIn;
        $this->saveOptIn = $saveOptIn;
        $this->runtime = $runtime;
        $this->archive = $archive;
        $this->eligible = $eligible;
        $this->isConfigured = $isConfigured;
        $this->ledger = $ledger;
        $this->providerFactory = $providerFactory;
        $this->postUrl = $postUrl;
        $this->settingsUrl = $settingsUrl;
        $this->reportPageUrl = $reportPageUrl;
    }

    /**
     * @param array<string, mixed> $request
     * @return array{status: int, body: string, location: string|null}
     */
    public function settings(string $method, array $request): array
    {
        if ($method !== 'POST') {
            return $this->fail(405, 'method_not_allowed', true);
        }
        if (!$this->allowed()) {
            return $this->fail(403, 'forbidden', true);
        }
        $nonce = self::field($request, 'seo_lab_nonce');
        if ($nonce === null || !$this->verify('seo_lab_ai_settings_' . $this->blogId, $nonce)) {
            return $this->fail(403, 'bad_nonce', true);
        }
        $value = self::field($request, self::OPTION);
        if ($value !== '0' && $value !== '1') {
            return $this->fail(400, 'bad_request', true);
        }
        if ($value === '1' && ($this->runtime)(true) !== self::RUNTIME_READY) {
            return $this->fail(409, 'runtime_unavailable', true);
        }
        if (!($this->saveOptIn)($value)) {
            return $this->fail(503, 'storage_error', true);
        }
        return ['status' => 302, 'body' => '', 'location' => $this->settingsUrl . '&saved=1'];
    }

    /**
     * @param array<string, mixed> $request
     * @return array{status: int, body: string, location: string|null}
     */
    public function preview(string $method, array $request): array
    {
        if ($method !== 'POST') {
            return $this->fail(405, 'method_not_allowed');
        }
        if (!$this->allowed()) {
            return $this->fail(403, 'forbidden');
        }
        $reportId = ReportActions::parseUuid($request['report'] ?? null);
        $provider = self::choice($request, 'provider', self::PROVIDERS);
        $model = self::field($request, 'model');
        $language = self::choice($request, 'language', self::LANGUAGES);
        if ($reportId === null || $provider === null || $model === null || $language === null
            || !WordPressReviewProvider::isValidModel($model)) {
            return $this->fail(400, 'bad_request');
        }
        $phaseNonce = self::field($request, 'seo_lab_nonce');
        if ($phaseNonce === null || !$this->verify(ReviewConfirmation::phaseAction('preview', $this->blogId, $reportId), $phaseNonce)) {
            return $this->fail(403, 'bad_nonce');
        }
        $userId = $this->user();
        if ($userId < 1) {
            return $this->fail(403, 'forbidden');
        }
        if (!($this->optIn)()) {
            return $this->fail(403, 'disabled');
        }
        if (($this->runtime)(true) !== self::RUNTIME_READY) {
            return $this->fail(503, 'unsupported_runtime');
        }

        $report = $this->archive->find($reportId);
        if ($report === null) {
            return $this->fail(404, 'not_found');
        }
        if (!($this->eligible)($report->postId())) {
            return $this->fail(409, 'not_reviewable');
        }
        if (!($this->isConfigured)($provider)) {
            return $this->fail(503, 'unconfigured');
        }
        $built = $this->build($report, $language);
        if ($built === null) {
            return $this->fail(409, 'not_reviewable');
        }
        [, $prompt] = $built;

        $issuedAt = ($this->now)();
        $confirmationId = ($this->randomHex)();
        if (preg_match(ReviewConfirmation::ID_PATTERN, $confirmationId) !== 1) {
            return $this->fail(503, 'storage_error');
        }
        $digest = $this->digest($reportId, $report, $provider, $model, $language, $prompt, $userId, $issuedAt, $confirmationId);
        if ($digest === null) {
            return $this->fail(400, 'bad_request');
        }

        $page = AiReviewView::preview([
            'reportId' => $reportId,
            'provider' => $provider,
            'model' => $model,
            'language' => $language,
            'system' => $prompt['system'],
            'data' => $prompt['data'],
            'units' => WordPressAiLedger::estimateUnits($prompt),
            'maxOutput' => WordPressReviewProvider::MAX_OUTPUT_TOKENS,
            'reasoningEffort' => WordPressReviewProvider::reasoningEffort($provider, $model),
            'issuedAt' => $issuedAt,
            'confirmationId' => $confirmationId,
            'phaseNonce' => ($this->createNonce)(ReviewConfirmation::phaseAction('confirm', $this->blogId, $reportId)),
            'contextNonce' => ($this->createNonce)(ReviewConfirmation::contextAction($digest)),
        ], $this->postUrl);
        return $this->html(200, AiReviewView::page('SEO Lab', $page));
    }

    /**
     * @param array<string, mixed> $request
     * @return array{status: int, body: string, location: string|null}
     */
    public function confirm(string $method, array $request): array
    {
        if ($method !== 'POST') {
            return $this->fail(405, 'method_not_allowed');
        }
        if (!$this->allowed()) {
            return $this->fail(403, 'forbidden');
        }
        $reportId = ReportActions::parseUuid($request['report'] ?? null);
        $provider = self::choice($request, 'provider', self::PROVIDERS);
        $model = self::field($request, 'model');
        $language = self::choice($request, 'language', self::LANGUAGES);
        $issued = self::field($request, 'issued_at');
        $confirmationId = self::field($request, 'confirmation_id');
        if ($reportId === null || $provider === null || $model === null || $language === null
            || $issued === null || preg_match(self::ISSUED_PATTERN, $issued) !== 1
            || $confirmationId === null || preg_match(ReviewConfirmation::ID_PATTERN, $confirmationId) !== 1
            || !WordPressReviewProvider::isValidModel($model)) {
            return $this->fail(400, 'bad_request');
        }
        $issuedAt = (int) $issued;
        $phaseNonce = self::field($request, 'seo_lab_nonce');
        $contextNonce = self::field($request, 'seo_lab_ctx_nonce');
        if ($phaseNonce === null || $contextNonce === null) {
            return $this->fail(403, 'bad_nonce');
        }
        $userId = $this->user();
        if ($userId < 1) {
            return $this->fail(403, 'forbidden');
        }
        $age = ($this->now)() - $issuedAt;
        if ($age < 0 || $age > ReviewConfirmation::TTL_SECONDS) {
            return $this->fail(409, 'preview_required');
        }
        if (!$this->verify(ReviewConfirmation::phaseAction('confirm', $this->blogId, $reportId), $phaseNonce)) {
            return $this->fail(403, 'bad_nonce');
        }
        if (!($this->optIn)()) {
            return $this->fail(403, 'disabled');
        }
        if (($this->runtime)(true) !== self::RUNTIME_READY) {
            return $this->fail(503, 'unsupported_runtime');
        }

        $report = $this->archive->find($reportId);
        if ($report === null) {
            return $this->fail(404, 'not_found');
        }
        if (!($this->eligible)($report->postId())) {
            return $this->fail(409, 'not_reviewable');
        }
        $built = $this->build($report, $language);
        if ($built === null) {
            return $this->fail(409, 'not_reviewable');
        }
        [$review, $prompt] = $built;

        $digest = $this->digest($reportId, $report, $provider, $model, $language, $prompt, $userId, $issuedAt, $confirmationId);
        if ($digest === null) {
            return $this->fail(400, 'bad_request');
        }
        if (!$this->verify(ReviewConfirmation::contextAction($digest), $contextNonce)) {
            return $this->fail(409, 'preview_required');
        }

        $units = WordPressAiLedger::estimateUnits($prompt);
        $expired = false;
        $closed = null;
        $admit = function () use (&$expired, &$closed, $userId, $issuedAt, $confirmationId, $units): ?string {
            $now = ($this->now)();
            if ($now < $issuedAt || $now - $issuedAt > ReviewConfirmation::TTL_SECONDS) {
                $expired = true;
                return 'rate_limited';
            }
            if (!$this->allowed()) {
                $closed = 'forbidden';
                return 'rate_limited';
            }
            if (!($this->optIn)()) {
                $closed = 'disabled';
                return 'rate_limited';
            }
            return $this->ledger->admit((int) $this->blogId, $userId, $confirmationId, $issuedAt, $now, $units);
        };
        $reviewer = ($this->providerFactory)(true, $provider, $model, $admit);
        $result = $reviewer->review($review);
        if ($expired) {
            return $this->fail(409, 'preview_required');
        }
        if ($closed !== null) {
            return $this->fail(403, $closed);
        }
        if ($result->code() !== null) {
            return in_array($result->code(), ['rate_limited', 'budget_exhausted'], true)
                ? $this->fail(429, $result->code())
                : $this->fail(503, $result->code());
        }

        $items = [];
        foreach ($result->items() as $item) {
            $text = AiReviewView::sanitizeText($item['text']);
            if ($text === '') {
                return $this->fail(503, 'invalid_response');
            }
            $items[] = ['kind' => $item['kind'], 'text' => $text, 'refs' => $item['refs']];
        }
        $usage = $reviewer instanceof WordPressReviewProvider ? $reviewer->lastUsage() : null;
        $body = AiReviewView::result($items, self::refLabels($review->payload()), $usage, $this->reportPageUrl . '&report=' . $reportId);
        return $this->html(200, AiReviewView::page('SEO Lab', $body));
    }

    /**
     * Richiesta di revisione e stringhe di prompt, oppure null se il rapporto non e' revisionabile.
     *
     * @return array{0: ReviewRequest, 1: array{system: string, data: string}}|null
     */
    private function build(ScanReport $report, string $language): ?array
    {
        try {
            $review = ReviewRequest::fromReport($report, $language);
            $prompt = ReviewPrompt::fromRequest($review);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        return [$review, $prompt];
    }

    /**
     * @param array{system: string, data: string} $prompt
     */
    private function digest(string $reportId, ScanReport $report, string $provider, string $model, string $language, array $prompt, int $userId, int $issuedAt, string $confirmationId): ?string
    {
        return ReviewConfirmation::digest(ReviewConfirmation::tuple(
            $this->blogId,
            $userId,
            $reportId,
            ReviewConfirmation::reportDigest($report),
            $provider,
            $model,
            $language,
            $prompt,
            $issuedAt,
            $confirmationId,
            WordPressReviewProvider::reasoningEffort($provider, $model)
        ));
    }

    private function allowed(): bool
    {
        return ($this->siteOk)() && ($this->can)();
    }

    private function user(): int
    {
        return (int) ($this->userId)();
    }

    private function verify(string $action, string $value): bool
    {
        return ($this->verifyNonce)($action, $value);
    }

    /**
     * Etichette per ref dalla proiezione gia' validata: regola, indice della prova e kind.
     *
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private static function refLabels(array $payload): array
    {
        $labels = [];
        foreach ($payload['findings'] as $finding) {
            $ruleId = (string) $finding['rule_id'];
            $labels[$ruleId] = sprintf(__('Rule %s', 'seo-lab'), $ruleId);
            foreach ($finding['evidence'] as $index => $item) {
                $labels[(string) $item['ref']] = sprintf(__('Rule %1$s, evidence %2$d (%3$s)', 'seo-lab'), $ruleId, $index + 1, (string) $item['kind']);
            }
        }
        return $labels;
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function field(array $request, string $name): ?string
    {
        $value = $request[$name] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > self::FIELD_MAX) {
            return null;
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $request
     * @param string[]             $allowed
     */
    private static function choice(array $request, string $name, array $allowed): ?string
    {
        $value = self::field($request, $name);
        return $value !== null && in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * @return array{status: int, body: string, location: null}
     */
    private function fail(int $status, string $code, bool $settings = false): array
    {
        $back = $settings ? $this->settingsUrl : $this->reportPageUrl;
        return $this->html($status, AiReviewView::page('SEO Lab', AiReviewView::error($code, $back)));
    }

    /**
     * @return array{status: int, body: string, location: null}
     */
    private function html(int $status, string $body): array
    {
        return ['status' => $status, 'body' => $body, 'location' => null];
    }
}
