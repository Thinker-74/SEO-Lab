<?php

declare(strict_types=1);

namespace SeoLab\Diagnosis;

use SeoLab\Contracts\ExpectedProvider;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Contracts\ScanReport;

/**
 * Scansione di un contenuto: eleggibilita, cooldown, una sola cattura e una sola risoluzione Yoast, poi gli otto esiti
 * delle regole P0 nel ScanReport. Nessuna mutazione: il servizio non salva nulla.
 */
final class ScanService
{
    /** @var callable(int): bool */
    private $eligible;

    /** @var callable(): bool */
    private $admit;

    /** @var callable(int): ObservedSnapshot */
    private $capture;

    /** @var ExpectedProvider */
    private $provider;

    /** @var string */
    private $providerVersion;

    /** @var callable(): string */
    private $uuid;

    /** @var CanonicalRobotsDiagnoser */
    private $canonical;

    /** @var JsonLdDiagnoser */
    private $jsonLd;

    /**
     * @param callable(int): bool              $eligible        riconvalida tipo, stato, password e permalink senza HTTP
     * @param callable(): bool                 $admit           cooldown del sito: false se il rate limit blocca
     * @param callable(int): ObservedSnapshot  $capture         cattura difensiva dell'ID validato
     * @param string                           $providerVersion versione Yoast effettiva, o "none"/"unavailable"
     * @param callable(): string               $uuid            generatore UUID del report
     */
    public function __construct(callable $eligible, callable $admit, callable $capture, ExpectedProvider $provider, string $providerVersion, callable $uuid)
    {
        $this->eligible = $eligible;
        $this->admit = $admit;
        $this->capture = $capture;
        $this->provider = $provider;
        $this->providerVersion = $providerVersion;
        $this->uuid = $uuid;
        $this->canonical = new CanonicalRobotsDiagnoser($provider);
        $this->jsonLd = new JsonLdDiagnoser($provider);
    }

    /**
     * @throws ScanRefused se il contenuto non e idoneo o il rate limit blocca
     */
    public function scan(int $postId): ScanReport
    {
        if (!($this->eligible)($postId)) {
            throw new ScanRefused(ScanRefused::NOT_ELIGIBLE);
        }
        if (!($this->admit)()) {
            throw new ScanRefused(ScanRefused::RATE_LIMITED);
        }

        $observed = ($this->capture)($postId);
        $expected = $this->provider->forPost($postId);
        $outcomes = array_merge(
            $this->canonical->diagnoseResolved($expected, $observed),
            $this->jsonLd->diagnoseResolved($expected, $observed)
        );

        $findings = [];
        $outcomeData = [];
        foreach ($outcomes as $outcome) {
            $outcomeData[] = $outcome->toArray();
            if ($outcome->finding() !== null) {
                $findings[] = $outcome->finding()->toArray();
            }
        }

        return new ScanReport(['scan_id' => ($this->uuid)(), 'schema_version' => '1', 'post_id' => $postId,
            'source' => ['provider' => 'yoast', 'provider_version' => $this->providerVersion],
            'expected' => $expected->toArray(),
            'rule_outcomes' => $outcomeData,
            'findings' => $findings,
        ] + $observed->toArray());
    }
}
