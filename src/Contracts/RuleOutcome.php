<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Esito di una regola su uno snapshot: anomalia (confirmed_anomaly, no_anomaly, inconclusive, not_tested),
 * stato della causa e, solo per confirmed_anomaly, il Finding corrispondente per ID, versione e causa.
 * Per gli esiti non positivi la causa e' unknown e nessun Finding e' associato.
 */
final class RuleOutcome
{
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+\z/';

    /** @var string */
    private $ruleId;

    /** @var string */
    private $ruleVersion;

    /** @var string */
    private $state;

    /** @var string */
    private $causeState;

    /** @var Finding|null */
    private $finding;

    public function __construct(string $ruleId, string $ruleVersion, string $state, string $causeState, ?Finding $finding)
    {
        if (RuleCatalog::familyOf($ruleId) === null) {
            throw new \InvalidArgumentException('rule_id non presente nel catalogo: ' . $ruleId);
        }
        if (!preg_match(self::VERSION_PATTERN, $ruleVersion)) {
            throw new \InvalidArgumentException('rule_version non valida per ' . $ruleId);
        }
        States::assertIn('state', $state, States::ANOMALY_STATES);
        States::assertIn('cause_state', $causeState, States::CAUSE_STATES);

        if ($state === States::ANOMALY_CONFIRMED) {
            if ($finding === null) {
                throw new \InvalidArgumentException('esito confirmed_anomaly senza finding per ' . $ruleId);
            }
            if ($finding->ruleId() !== $ruleId) {
                throw new \InvalidArgumentException('finding con ID diverso dall esito ' . $ruleId);
            }
            if ($finding->ruleVersion() !== $ruleVersion) {
                throw new \InvalidArgumentException('finding ' . $ruleId . ': versione non coincidente con l esito');
            }
            if ($finding->causeState() !== $causeState) {
                throw new \InvalidArgumentException('finding ' . $ruleId . ': causa non coincidente con cause_state dell esito');
            }
        } else {
            if ($finding !== null) {
                throw new \InvalidArgumentException('finding associato a esito non positivo (' . $state . ') per ' . $ruleId);
            }
            if ($causeState !== States::CAUSE_UNKNOWN) {
                throw new \InvalidArgumentException('causa diversa da unknown per esito non positivo di ' . $ruleId);
            }
        }

        $this->ruleId = $ruleId;
        $this->ruleVersion = $ruleVersion;
        $this->state = $state;
        $this->causeState = $causeState;
        $this->finding = $finding;
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

    public function causeState(): string
    {
        return $this->causeState;
    }

    public function finding(): ?Finding
    {
        return $this->finding;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'rule_version' => $this->ruleVersion,
            'state' => $this->state,
            'cause_state' => $this->causeState,
        ];
    }
}
