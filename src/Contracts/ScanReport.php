<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Report di una singola scansione nel formato di spec par. 5: snapshot atteso, osservazione, esiti per regola
 * e findings positivi. Findings e esiti sono liste sequenziali; ogni anomalia confermata ha un solo esito e un
 * solo finding corrispondenti. Non conserva HTML integrale. Una capture bloccata o fallita non produce anomalie confermate.
 */
final class ScanReport
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /** @var string */
    private $scanId;

    /** @var int */
    private $postId;

    /** @var array<string, string> */
    private $source;

    /** @var ObservedSnapshot */
    private $observed;

    /** @var ExpectedSnapshot */
    private $expected;

    /** @var RuleOutcome[] */
    private $outcomes;

    /** @var Finding[] */
    private $findings;

    /**
     * @param array<string, mixed> $data chiavi: scan_id, schema_version, post_id, source, expected, capture_status,
     *                                   requested_url, observed_url, captured_at_utc, http, observed_values,
     *                                   findings, rule_outcomes
     */
    public function __construct(array $data)
    {
        $scanId = $data['scan_id'] ?? null;
        if (!is_string($scanId) || !preg_match(self::UUID_PATTERN, $scanId)) {
            throw new \InvalidArgumentException('scan_id deve essere UUID');
        }

        if (($data['schema_version'] ?? null) !== '1') {
            throw new \InvalidArgumentException('schema_version non supportata');
        }

        $postId = $data['post_id'] ?? null;
        if (!is_int($postId) || $postId <= 0) {
            throw new \InvalidArgumentException('post_id deve essere intero positivo');
        }

        $source = $data['source'] ?? null;
        if (!is_array($source) || !isset($source['provider'], $source['provider_version'])
            || !is_string($source['provider']) || $source['provider'] === ''
            || !is_string($source['provider_version']) || $source['provider_version'] === '') {
            throw new \InvalidArgumentException('source: provider e provider_version obbligatori');
        }

        $expected = $data['expected'] ?? null;
        if (!is_array($expected)) {
            throw new \InvalidArgumentException('expected deve essere oggetto');
        }

        $observed = new ObservedSnapshot($data);

        if (!array_key_exists('findings', $data) || !ValueCopy::isList($data['findings'])) {
            throw new \InvalidArgumentException('findings deve essere una lista');
        }
        if (!array_key_exists('rule_outcomes', $data) || !ValueCopy::isList($data['rule_outcomes'])) {
            throw new \InvalidArgumentException('rule_outcomes deve essere una lista');
        }

        $findingsById = [];
        foreach ($data['findings'] as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('finding non oggetto');
            }
            $finding = new Finding($item);
            if (isset($findingsById[$finding->ruleId()])) {
                throw new \InvalidArgumentException('finding duplicato per ' . $finding->ruleId());
            }
            $findingsById[$finding->ruleId()] = $finding;
        }

        $outcomes = [];
        $findings = [];
        $seen = [];
        foreach ($data['rule_outcomes'] as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('rule_outcome non oggetto');
            }
            $ruleId = $item['rule_id'] ?? null;
            if (!is_string($ruleId) || RuleCatalog::familyOf($ruleId) === null) {
                throw new \InvalidArgumentException('rule_outcome: rule_id non presente nel catalogo');
            }
            if (isset($seen[$ruleId])) {
                throw new \InvalidArgumentException('rule_outcome duplicato per ' . $ruleId);
            }
            $seen[$ruleId] = true;

            $ruleVersion = $item['rule_version'] ?? null;
            $state = $item['state'] ?? null;
            $causeState = $item['cause_state'] ?? null;
            if (!is_string($ruleVersion) || !is_string($state) || !is_string($causeState)) {
                throw new \InvalidArgumentException('rule_outcome ' . $ruleId . ': rule_version, state e cause_state sono stringhe');
            }

            $finding = $findingsById[$ruleId] ?? null;
            $outcome = new RuleOutcome($ruleId, $ruleVersion, $state, $causeState, $finding);
            $outcomes[] = $outcome;
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        foreach ($findingsById as $ruleId => $finding) {
            if (!isset($seen[$ruleId])) {
                throw new \InvalidArgumentException('finding senza esito per ' . $ruleId);
            }
        }

        if ($findings !== [] && in_array($observed->status(), [States::SCAN_BLOCKED, States::SCAN_FAILED], true)) {
            throw new \InvalidArgumentException('anomalia confermata con capture ' . $observed->status());
        }

        $this->scanId = $scanId;
        $this->postId = $postId;
        $this->source = ['provider' => $source['provider'], 'provider_version' => $source['provider_version']];
        $this->observed = $observed;
        $this->expected = new ExpectedSnapshot($expected);
        $this->outcomes = $outcomes;
        $this->findings = $findings;
    }

    public function scanId(): string
    {
        return $this->scanId;
    }

    public function postId(): int
    {
        return $this->postId;
    }

    /**
     * @return array<string, string>
     */
    public function source(): array
    {
        return $this->source;
    }

    public function observed(): ObservedSnapshot
    {
        return $this->observed;
    }

    public function expected(): ExpectedSnapshot
    {
        return $this->expected;
    }

    /**
     * Esiti validati per regola, inclusi inconclusive e not_tested.
     *
     * @return RuleOutcome[]
     */
    public function ruleOutcomes(): array
    {
        return $this->outcomes;
    }

    /**
     * Solo i risultati positivi (anomalie confermate).
     *
     * @return Finding[]
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * Forma di serializzazione compatibile con il costruttore: ScanReport(toArray()) ricostruisce lo stesso report.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scan_id' => $this->scanId,
            'schema_version' => '1',
            'post_id' => $this->postId,
            'source' => $this->source,
        ] + $this->observed->toArray() + [
            'expected' => $this->expected->toArray(),
            'rule_outcomes' => array_map(static function (RuleOutcome $outcome): array {
                return $outcome->toArray();
            }, $this->outcomes),
            'findings' => array_map(static function (Finding $finding): array {
                return $finding->toArray();
            }, $this->findings),
        ];
    }
}
