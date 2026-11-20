<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Risultato positivo di una regola: anomalia confermata con evidenza circoscritta, test successivo e metodo di verifica.
 * Anomalia, causa, severita' restano campi separati. La causa confirmed richiede una prova tecnica isolante,
 * distinta dalle evidenze dell'anomalia.
 */
final class Finding
{
    private const EVIDENCE_KINDS = ['html', 'jsonld', 'header'];
    private const EVIDENCE_EXCERPT_MAX_BYTES = 1024;
    private const PROOF_ISOLATING_KINDS = ['isolation', 'equivalent_test'];
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+\z/';

    /** @var string */
    private $ruleId;

    /** @var string */
    private $ruleVersion;

    /** @var string */
    private $state;

    /** @var string */
    private $severity;

    /** @var array<string, mixed> */
    private $expected;

    /** @var array<string, mixed> */
    private $observed;

    /** @var array<int, array<string, mixed>> */
    private $evidence;

    /** @var array<string, mixed> */
    private $cause;

    /** @var string */
    private $nextTest;

    /** @var string */
    private $verificationMethod;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $ruleId = $data['rule_id'] ?? null;
        if (!is_string($ruleId) || RuleCatalog::familyOf($ruleId) === null) {
            throw new \InvalidArgumentException('rule_id non presente nel catalogo: ' . json_encode($ruleId));
        }

        $ruleVersion = $data['rule_version'] ?? null;
        if (!is_string($ruleVersion) || !preg_match(self::VERSION_PATTERN, $ruleVersion)) {
            throw new \InvalidArgumentException('rule_version non valida');
        }

        $state = $data['state'] ?? null;
        States::assertIn('state', $state, States::ANOMALY_STATES);
        if ($state !== States::ANOMALY_CONFIRMED) {
            throw new \InvalidArgumentException('Finding rappresenta solo anomalie confermate, stato ricevuto: ' . $state);
        }

        $severity = $data['severity'] ?? null;
        States::assertIn('severity', $severity, States::SEVERITY_STATES);

        $expected = $data['expected'] ?? null;
        $observed = $data['observed'] ?? null;
        if (!is_array($expected) || !is_array($observed)) {
            throw new \InvalidArgumentException('expected e observed devono essere oggetti');
        }

        $evidence = $data['evidence'] ?? null;
        if (!ValueCopy::isList($evidence) || $evidence === []) {
            throw new \InvalidArgumentException('evidence: richiesta lista non vuota');
        }
        foreach ($evidence as $item) {
            self::assertEvidence($item);
        }

        $cause = $data['cause'] ?? null;
        if (!is_array($cause)) {
            throw new \InvalidArgumentException('cause deve essere oggetto');
        }
        $causeState = $cause['state'] ?? null;
        States::assertIn('cause.state', $causeState, States::CAUSE_STATES);
        $candidates = $cause['candidates'] ?? [];
        $proof = $cause['proof'] ?? [];
        if (!ValueCopy::isList($candidates)) {
            throw new \InvalidArgumentException('cause.candidates deve essere lista');
        }
        if (!ValueCopy::isList($proof)) {
            throw new \InvalidArgumentException('cause.proof deve essere lista');
        }
        if ($causeState === States::CAUSE_UNKNOWN && ($candidates !== [] || $proof !== [])) {
            throw new \InvalidArgumentException('causa unknown senza candidati e senza prova');
        }
        if ($causeState !== States::CAUSE_UNKNOWN && $candidates === []) {
            throw new \InvalidArgumentException('causa ' . $causeState . ' senza candidati');
        }
        foreach ($proof as $item) {
            self::assertProof($item, $evidence);
        }
        if ($causeState === States::CAUSE_CONFIRMED && !self::hasIsolatingProof($proof)) {
            throw new \InvalidArgumentException('causa confirmed senza prova isolante o tecnica equivalente');
        }

        $nextTest = $data['next_test'] ?? null;
        $verificationMethod = $data['verification_method'] ?? null;
        if (!is_string($nextTest) || trim($nextTest) === '') {
            throw new \InvalidArgumentException('next_test obbligatorio per un risultato positivo');
        }
        if (!is_string($verificationMethod) || trim($verificationMethod) === '') {
            throw new \InvalidArgumentException('verification_method obbligatorio per un risultato positivo');
        }

        $this->ruleId = $ruleId;
        $this->ruleVersion = $ruleVersion;
        $this->state = $state;
        $this->severity = $severity;
        $this->expected = ValueCopy::detach($expected, 'expected');
        $this->observed = ValueCopy::detach($observed, 'observed');
        $this->evidence = ValueCopy::detach($evidence, 'evidence');
        $this->cause = [
            'state' => $causeState,
            'candidates' => ValueCopy::detach($candidates, 'cause.candidates'),
            'proof' => ValueCopy::detach($proof, 'cause.proof'),
        ];
        $this->nextTest = $nextTest;
        $this->verificationMethod = $verificationMethod;
    }

    public function ruleId(): string
    {
        return $this->ruleId;
    }

    public function ruleVersion(): string
    {
        return $this->ruleVersion;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function causeState(): string
    {
        return $this->cause['state'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'rule_version' => $this->ruleVersion,
            'state' => $this->state,
            'severity' => $this->severity,
            'expected' => $this->expected,
            'observed' => $this->observed,
            'evidence' => $this->evidence,
            'cause' => $this->cause,
            'next_test' => $this->nextTest,
            'verification_method' => $this->verificationMethod,
        ];
    }

    /**
     * @param mixed $item
     */
    private static function assertEvidence($item): void
    {
        if (!is_array($item)) {
            throw new \InvalidArgumentException('evidenza non oggetto');
        }
        $kind = $item['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::EVIDENCE_KINDS, true)) {
            throw new \InvalidArgumentException('evidence.kind fuori enum: ' . json_encode($kind));
        }
        if ($kind === 'header') {
            if (!isset($item['name']) || !is_string($item['name']) || $item['name'] === '') {
                throw new \InvalidArgumentException('evidenza header senza name');
            }
        } elseif (!isset($item['locator']) || !is_string($item['locator']) || $item['locator'] === '') {
            throw new \InvalidArgumentException('evidenza ' . $kind . ' senza locator');
        }
        if (isset($item['excerpt'])) {
            if (!is_string($item['excerpt'])) {
                throw new \InvalidArgumentException('evidence.excerpt deve essere stringa');
            }
            if (strlen($item['excerpt']) > self::EVIDENCE_EXCERPT_MAX_BYTES) {
                throw new \InvalidArgumentException('evidence.excerpt oltre ' . self::EVIDENCE_EXCERPT_MAX_BYTES . ' byte');
            }
        }
    }

    /**
     * Prova della causa: oggetto con kind, method e result stringhe non vuote, distinto dalle evidenze.
     *
     * @param mixed $item
     * @param array<int, mixed> $evidence
     */
    private static function assertProof($item, array $evidence): void
    {
        foreach ($evidence as $ev) {
            if (ValueCopy::canonical($item) === ValueCopy::canonical($ev)) {
                throw new \InvalidArgumentException('la prova della causa deve essere distinta dalle evidenze dell anomalia');
            }
        }
        if (!is_array($item)) {
            throw new \InvalidArgumentException('prova non valida: atteso oggetto con kind, method e result');
        }
        foreach (['kind', 'method', 'result'] as $field) {
            if (!isset($item[$field]) || !is_string($item[$field]) || trim($item[$field]) === '') {
                throw new \InvalidArgumentException('prova non valida: ' . $field . ' deve essere stringa non vuota');
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $proof
     */
    private static function hasIsolatingProof(array $proof): bool
    {
        foreach ($proof as $item) {
            if (in_array($item['kind'], self::PROOF_ISOLATING_KINDS, true)) {
                return true;
            }
        }
        return false;
    }
}
