<?php

declare(strict_types=1);

namespace SeoLab\Admin;

use SeoLab\Contracts\Finding;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\ScanReport;

/**
 * Proiezione di export con allowlist: ID e versione del report, fonte, timestamp, URL pubblici senza userinfo, esiti,
 * finding, causa e prove ridotte. Escluse: corpo grezzo, header completi, cookie, credenziali, percorsi interni, log.
 */
final class ExportProjection
{
    private const EXCERPT_MAX_BYTES = 300;

    /**
     * @return array<string, mixed>
     */
    public static function fromReport(ScanReport $report): array
    {
        $observed = $report->observed();
        return [
            'report_id' => $report->scanId(),
            'schema_version' => '1',
            'post_id' => $report->postId(),
            'source' => $report->source(),
            'captured_at_utc' => $observed->capturedAtUtc(),
            'capture_status' => $observed->status(),
            'requested_url' => self::publicUrl($observed->requestedUrl()),
            'observed_url' => self::publicUrl($observed->observedUrl()),
            'http' => [
                'status' => $observed->httpStatus(),
                'content_type' => $observed->contentType(),
            ],
            'rule_outcomes' => array_map([self::class, 'outcome'], $report->ruleOutcomes()),
            'findings' => array_map([self::class, 'finding'], $report->findings()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function outcome(RuleOutcome $outcome): array
    {
        return [
            'rule_id' => $outcome->ruleId(),
            'rule_version' => $outcome->ruleVersion(),
            'state' => $outcome->state(),
            'cause_state' => $outcome->causeState(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function finding(Finding $finding): array
    {
        $data = $finding->toArray();
        $evidence = [];
        foreach ($data['evidence'] as $item) {
            $evidence[] = [
                'kind' => $item['kind'],
                'locator' => $item['locator'],
                'excerpt' => self::excerpt((string) $item['excerpt']),
            ];
        }
        return [
            'rule_id' => $data['rule_id'],
            'rule_version' => $data['rule_version'],
            'state' => $data['state'],
            'severity' => $data['severity'],
            'expected' => $data['expected'],
            'observed' => $data['observed'],
            'evidence' => $evidence,
            'cause' => $data['cause'],
            'next_test' => $data['next_test'],
            'verification_method' => $data['verification_method'],
        ];
    }

    /**
     * Rimuove userinfo (utente:password@) dall'URL pubblico.
     */
    public static function publicUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        return (string) preg_replace('~^(https?://)[^/?#]*@~i', '$1', $url);
    }

    /**
     * Taglio a non piu' di EXCERPT_MAX_BYTES senza spezzare caratteri UTF-8.
     */
    public static function excerpt(string $text): string
    {
        $out = substr($text, 0, self::EXCERPT_MAX_BYTES);
        while ($out !== '' && preg_match('//u', $out) !== 1) {
            $out = substr($out, 0, -1);
        }
        return $out;
    }
}
