<?php

declare(strict_types=1);

namespace SeoLab\Ai;

use SeoLab\Contracts\ScanReport;

/**
 * Richiesta di revisione AI opzionale (P1.0): proiezione minima di un ScanReport con findings.
 * Il payload contiene solo ID regola, versione, stati, severita', causa e riferimenti opachi alle evidenze.
 * URL, locator, estratti, valori osservati e attesi, ID di sito, post e scansione restano fuori dal payload.
 * Valore immutabile: nessun riferimento al report di origine.
 */
final class ReviewRequest
{
    public const SCHEMA_VERSION = '1';
    public const PROMPT_VERSION = 'review-1';
    public const LANGUAGES = ['it', 'en'];
    public const MAX_FINDINGS = 8;
    public const MAX_EVIDENCE_PER_FINDING = 4;
    public const MAX_PAYLOAD_BYTES = 8192;

    /** @var array<string, mixed> */
    private $payload;

    /** @var string[] */
    private $referenceIds;

    /** @var array<string, array<string, mixed>> */
    private $evidenceByRef;

    /**
     * @param array<string, mixed>                $payload
     * @param string[]                            $referenceIds
     * @param array<string, array<string, mixed>> $evidenceByRef
     */
    private function __construct(array $payload, array $referenceIds, array $evidenceByRef)
    {
        $this->payload = $payload;
        $this->referenceIds = $referenceIds;
        $this->evidenceByRef = $evidenceByRef;
    }

    /**
     * @throws \InvalidArgumentException lingua fuori dal set, report senza finding (anche blocked o failed),
     *                                   input oltre i cap o payload oltre 8 KiB
     */
    public static function fromReport(ScanReport $report, string $language): self
    {
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new \InvalidArgumentException('lingua non supportata');
        }

        $findings = $report->findings();
        if ($findings === [] || count($findings) > self::MAX_FINDINGS) {
            throw new \InvalidArgumentException('report non eleggibile per la revisione');
        }

        $items = [];
        $referenceIds = [];
        $evidenceByRef = [];
        foreach ($findings as $finding) {
            $ruleId = $finding->ruleId();
            $referenceIds[] = $ruleId;
            $evidence = [];
            foreach (array_slice($finding->evidence(), 0, self::MAX_EVIDENCE_PER_FINDING) as $index => $item) {
                $ref = $ruleId . ':' . ($index + 1);
                $evidence[] = ['ref' => $ref, 'kind' => (string) $item['kind']];
                $referenceIds[] = $ref;
                $evidenceByRef[$ref] = $item;
            }
            $items[] = [
                'rule_id' => $ruleId,
                'rule_version' => $finding->ruleVersion(),
                'state' => $finding->state(),
                'severity' => $finding->severity(),
                'cause_state' => $finding->causeState(),
                'evidence' => $evidence,
            ];
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'prompt_version' => self::PROMPT_VERSION,
            'language' => $language,
            'findings' => $items,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new \InvalidArgumentException('payload oltre 8 KiB');
        }

        return new self($payload, $referenceIds, $evidenceByRef);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Riferimenti validi per la risposta: ID di regola e ref delle evidenze incluse nel payload.
     *
     * @return string[]
     */
    public function referenceIds(): array
    {
        return $this->referenceIds;
    }
}
