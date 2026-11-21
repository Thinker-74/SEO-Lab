<?php

declare(strict_types=1);

namespace SeoLab\Admin;

use SeoLab\Contracts\Finding;
use SeoLab\Contracts\RuleCatalog;
use SeoLab\Contracts\RuleOutcome;
use SeoLab\Contracts\ScanReport;

/**
 * Vista HTML di un report per wp-admin. Ogni dato catturato, titolo o URL passa da esc_html(): nessun HTML ricevuto
 * viene eseguito o reso come markup. Le stringhe sono in dominio "seo-lab".
 */
final class ReportView
{
    private const FAMILY_LABELS = [
        'canonical' => 'Canonical',
        'robots' => 'Robots',
        'jsonld' => 'JSON-LD',
    ];

    /**
     * @param string|null $note messaggio sopra il report, per esempio "confronto non disponibile"
     */
    public static function render(ScanReport $report, ?string $note): string
    {
        $observed = $report->observed();
        $out = '';
        if ($note !== null) {
            $out .= '<div class="notice notice-warning"><p>' . esc_html($note) . '</p></div>';
        }
        $out .= '<h2>' . esc_html(__('Scan report', 'seo-lab')) . '</h2>';
        $out .= '<table class="widefat striped"><tbody>';
        $out .= self::row(__('Report ID', 'seo-lab'), $report->scanId());
        $out .= self::row(__('Post ID', 'seo-lab'), (string) $report->postId());
        $out .= self::row(__('Captured at (UTC)', 'seo-lab'), (string) $observed->capturedAtUtc());
        $out .= self::row(__('Requested URL', 'seo-lab'), (string) $observed->requestedUrl());
        $out .= self::row(__('Observed URL', 'seo-lab'), (string) $observed->observedUrl());
        $out .= self::row(__('Capture status', 'seo-lab'), self::captureLabel($observed->status()));
        $out .= self::row(__('Observation point', 'seo-lab'), __('Public HTML fetched by the server, no cookies or credentials', 'seo-lab'));
        $out .= self::row(__('Yoast version', 'seo-lab'), (string) ($report->source()['provider_version'] ?? ''));
        $out .= '</tbody></table>';

        if (in_array($observed->status(), ['blocked', 'failed'], true)) {
            $out .= '<p>' . esc_html(__('The public page could not be captured, so this scan does not confirm or exclude anomalies.', 'seo-lab')) . '</p>';
        }
        $out .= '<p>' . esc_html(__('This report shows what the public page exposed at capture time. Check the public excerpts before exporting it.', 'seo-lab')) . '</p>';

        $out .= self::families($report);
        $out .= self::outcomes($report->ruleOutcomes());
        $out .= self::findings($report->findings());
        return $out;
    }

    private static function families(ScanReport $report): string
    {
        $rows = '';
        foreach (RuleCatalog::families() as $family) {
            $expected = $report->expected()->resolved($family);
            $observed = $report->observed()->observedValue($family);
            $rows .= '<tr><th scope="row">' . esc_html(self::FAMILY_LABELS[$family] ?? $family) . '</th>'
                . '<td>' . esc_html(__('Expected', 'seo-lab')) . ': ' . esc_html($expected->availability()) . ' ' . esc_html(self::value($expected->value()))
                . (($expected->source() ?? '') !== '' ? ' (' . esc_html((string) $expected->source()) . ')' : '') . '</td>'
                . '<td>' . esc_html(__('Observed', 'seo-lab')) . ': ' . esc_html($observed->availability()) . ' ' . esc_html(self::value($observed->value())) . '</td></tr>';
        }
        return '<h3>' . esc_html(__('Expected and observed', 'seo-lab')) . '</h3>'
            . '<table class="widefat striped"><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @param RuleOutcome[] $outcomes
     */
    private static function outcomes(array $outcomes): string
    {
        $rows = '';
        foreach ($outcomes as $outcome) {
            $rows .= '<tr><td>' . esc_html($outcome->ruleId()) . '</td><td>' . esc_html($outcome->ruleVersion()) . '</td>'
                . '<td>' . esc_html(self::stateLabel($outcome->state())) . '</td>'
                . '<td>' . esc_html(self::causeLabel($outcome->causeState())) . '</td></tr>';
        }
        return '<h3>' . esc_html(__('Rules', 'seo-lab')) . '</h3>'
            . '<table class="widefat striped"><thead><tr><th>' . esc_html(__('Rule', 'seo-lab')) . '</th>'
            . '<th>' . esc_html(__('Version', 'seo-lab')) . '</th><th>' . esc_html(__('State', 'seo-lab')) . '</th>'
            . '<th>' . esc_html(__('Cause', 'seo-lab')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @param Finding[] $findings
     */
    private static function findings(array $findings): string
    {
        if ($findings === []) {
            return '<h3>' . esc_html(__('Findings', 'seo-lab')) . '</h3><p>' . esc_html(__('No confirmed anomaly in this scan.', 'seo-lab')) . '</p>';
        }
        $out = '<h3>' . esc_html(__('Findings', 'seo-lab')) . '</h3>';
        foreach ($findings as $finding) {
            $data = $finding->toArray();
            $help = RuleMessages::forRule($finding->ruleId());
            $out .= '<div class="seo-lab-finding"><h4>' . esc_html($help['title'] ?? $finding->ruleId()) . '</h4>';
            $out .= '<p><code>' . esc_html($finding->ruleId()) . '</code> v' . esc_html($finding->ruleVersion())
                . ' &middot; ' . esc_html(__('Severity', 'seo-lab')) . ': ' . esc_html($finding->severity())
                . ' &middot; ' . esc_html(__('Cause', 'seo-lab')) . ': ' . esc_html(self::causeLabel($finding->causeState())) . '</p>';
            $out .= '<p>' . esc_html($help['help'] ?? '') . '</p>';
            $out .= '<p>' . esc_html(__('Expected', 'seo-lab')) . ': ' . esc_html(self::value($data['expected'])) . '<br>'
                . esc_html(__('Observed', 'seo-lab')) . ': ' . esc_html(self::value($data['observed'])) . '</p>';
            foreach ($data['evidence'] as $item) {
                $out .= '<p>' . esc_html(__('Evidence', 'seo-lab')) . ' (' . esc_html((string) $item['kind']) . ') <code>'
                    . esc_html((string) $item['locator']) . '</code></p>';
                $out .= '<pre>' . esc_html((string) $item['excerpt']) . '</pre>';
            }
            $out .= '<p>' . esc_html(__('Next test', 'seo-lab')) . ': ' . esc_html((string) $data['next_test']) . '</p>';
            $out .= '<p>' . esc_html(__('Verification method', 'seo-lab')) . ': ' . esc_html((string) $data['verification_method']) . '</p>';
            $out .= '</div>';
        }
        return $out;
    }

    private static function row(string $label, string $value): string
    {
        return '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    /**
     * @param mixed $value
     */
    private static function value($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function captureLabel(string $status): string
    {
        switch ($status) {
            case 'ok':
                return __('Captured', 'seo-lab');
            case 'partial':
                return __('Partially captured', 'seo-lab');
            case 'blocked':
                return __('Blocked before fetching', 'seo-lab');
            default:
                return __('Failed', 'seo-lab');
        }
    }

    private static function stateLabel(string $state): string
    {
        switch ($state) {
            case 'confirmed_anomaly':
                return __('Confirmed anomaly', 'seo-lab');
            case 'no_anomaly':
                return __('No anomaly', 'seo-lab');
            case 'inconclusive':
                return __('Inconclusive', 'seo-lab');
            default:
                return __('Not tested', 'seo-lab');
        }
    }

    private static function causeLabel(string $cause): string
    {
        switch ($cause) {
            case 'confirmed':
                return __('Confirmed', 'seo-lab');
            case 'likely':
                return __('Likely', 'seo-lab');
            case 'possible':
                return __('Possible', 'seo-lab');
            default:
                return __('Unknown', 'seo-lab');
        }
    }
}
