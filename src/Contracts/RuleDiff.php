<?php

declare(strict_types=1);

namespace SeoLab\Contracts;

/**
 * Esito del confronto tra due scan per una regola (resolved, persists, changed, inconclusive).
 * Il calcolo del diff appartiene a S4; qui e' solo il valore immutabile validato.
 */
final class RuleDiff
{
    /** @var string */
    private $ruleId;

    /** @var string */
    private $state;

    public function __construct(string $ruleId, string $state)
    {
        if (RuleCatalog::familyOf($ruleId) === null) {
            throw new \InvalidArgumentException('rule_id non presente nel catalogo: ' . $ruleId);
        }
        States::assertIn('diff.state', $state, States::DIFF_STATES);
        $this->ruleId = $ruleId;
        $this->state = $state;
    }

    public function ruleId(): string
    {
        return $this->ruleId;
    }

    public function state(): string
    {
        return $this->state;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['rule_id' => $this->ruleId, 'state' => $this->state];
    }
}
