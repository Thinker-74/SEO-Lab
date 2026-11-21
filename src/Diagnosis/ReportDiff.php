<?php

declare(strict_types=1);

namespace SeoLab\Diagnosis;

use SeoLab\Contracts\Finding;
use SeoLab\Contracts\RuleCatalog;
use SeoLab\Contracts\RuleDiff;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\ScanReport;
use SeoLab\Contracts\States;
use SeoLab\Rules\CanonicalTargets;

/**
 * Confronto tra due report dello stesso contenuto, per regola P0, nell'ordine del catalogo.
 * Comparabilita: stesso post, capture entrambe ok, URL richiesto e finale equivalenti, versione di regola uguale.
 * Ignora timestamp, locator, excerpt e ordine delle liste usate come insiemi.
 */
final class ReportDiff
{
    /**
     * @return RuleDiff[]
     */
    public static function compare(ScanReport $before, ScanReport $after): array
    {
        $comparable = self::comparable($before, $after);
        $diffs = [];
        foreach (RuleCatalog::families() as $family) {
            foreach (RuleCatalog::rulesOf($family) as $ruleId) {
                $state = $comparable ? self::rule($ruleId, $before, $after) : States::DIFF_INCONCLUSIVE;
                $diffs[] = new RuleDiff($ruleId, $state);
            }
        }
        return $diffs;
    }

    private static function comparable(ScanReport $before, ScanReport $after): bool
    {
        if ($before->postId() !== $after->postId()) {
            return false;
        }
        if ($before->observed()->status() !== States::SCAN_OK || $after->observed()->status() !== States::SCAN_OK) {
            return false;
        }
        $requested = CanonicalTargets::normalize($before->observed()->requestedUrl());
        $finalUrl = CanonicalTargets::normalize($before->observed()->observedUrl());
        return $requested !== null && $requested === CanonicalTargets::normalize($after->observed()->requestedUrl())
            && $finalUrl !== null && $finalUrl === CanonicalTargets::normalize($after->observed()->observedUrl());
    }

    private static function rule(string $ruleId, ScanReport $before, ScanReport $after): string
    {
        $a = self::outcome($before, $ruleId);
        $b = self::outcome($after, $ruleId);
        if ($a === null || $b === null || $a->ruleVersion() !== $b->ruleVersion()) {
            return States::DIFF_INCONCLUSIVE;
        }
        $settled = [States::ANOMALY_CONFIRMED, States::ANOMALY_NONE];
        if (!in_array($a->state(), $settled, true) || !in_array($b->state(), $settled, true)) {
            return States::DIFF_INCONCLUSIVE;
        }

        $confirmedBefore = $a->state() === States::ANOMALY_CONFIRMED;
        $confirmedAfter = $b->state() === States::ANOMALY_CONFIRMED;
        if ($confirmedBefore && !$confirmedAfter) {
            return States::DIFF_RESOLVED;
        }
        if (!$confirmedBefore && $confirmedAfter) {
            return States::DIFF_CHANGED;
        }
        if (!$confirmedBefore) {
            return States::DIFF_PERSISTS;
        }
        return self::sameSubstance($a->finding(), $b->finding()) ? States::DIFF_PERSISTS : States::DIFF_CHANGED;
    }

    private static function outcome(ScanReport $report, string $ruleId): ?RuleOutcome
    {
        foreach ($report->ruleOutcomes() as $outcome) {
            if ($outcome->ruleId() === $ruleId) {
                return $outcome;
            }
        }
        return null;
    }

    private static function sameSubstance(?Finding $a, ?Finding $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        $left = $a->toArray();
        $right = $b->toArray();
        return self::canonical([$left['expected'], $left['observed']]) === self::canonical([$right['expected'], $right['observed']]);
    }

    /**
     * Forma canonica per il confronto: chiavi ordinate, locator esclusi, liste di scalari e di oggetti trattate come insiemi.
     *
     * @param mixed $value
     */
    private static function canonical($value): string
    {
        return (string) json_encode(self::normalize($value));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_values($value) === $value;
        if ($isList) {
            $items = array_map([self::class, 'normalize'], $value);
            usort($items, static function ($x, $y): int {
                return strcmp((string) json_encode($x), (string) json_encode($y));
            });
            return $items;
        }
        unset($value['locator']);
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }
        return $value;
    }
}
