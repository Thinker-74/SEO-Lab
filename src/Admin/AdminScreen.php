<?php

declare(strict_types=1);

namespace SeoLab\Admin;

use SeoLab\Contracts\ScanReport;
use SeoLab\Diagnosis\ReportDiff;
use SeoLab\Storage\WordPressReportArchive;

/**
 * Pagina wp-admin: elenco dei contenuti con Scan, report con Verify ed Export, confronto tra due report. Ogni form e'
 * POST verso admin-post.php con nonce specifico per azione, sito e UUID. Solo GET per la lettura dei report.
 */
final class AdminScreen
{
    private const PER_PAGE = 20;

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'seo-lab'));
        }
        $archive = new WordPressReportArchive();
        echo '<div class="wrap"><h1>' . esc_html__('SEO Lab', 'seo-lab') . '</h1>';
        echo '<p>' . esc_html__('Scans read the public page and the expected values of Yoast. Nothing on the site is changed.', 'seo-lab') . '</p>';
        if (!$archive->ensureSchema()) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Report storage is not available on this site.', 'seo-lab') . '</p></div>';
            echo '</div>';
            return;
        }
        $reportId = self::uuidParam('report');
        if ($reportId !== null) {
            self::renderReport($archive, $reportId, self::uuidParam('base'));
        } else {
            self::renderList($archive);
        }
        echo '</div>';
    }

    private static function renderList(WordPressReportArchive $archive): void
    {
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $query = new \WP_Query([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => self::PER_PAGE,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => false,
        ]);
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Content', 'seo-lab') . '</th>'
            . '<th>' . esc_html__('Latest report', 'seo-lab') . '</th><th>' . esc_html__('Scan', 'seo-lab') . '</th></tr></thead><tbody>';
        foreach ($query->posts as $postId) {
            $postId = (int) $postId;
            $latest = $archive->forPost($postId);
            $link = $latest !== [] ? '<a href="' . esc_url(self::pageUrl(['report' => $latest[0]->scanId()])) . '">'
                . esc_html__('Open', 'seo-lab') . '</a>' : esc_html__('None', 'seo-lab');
            echo '<tr><td>' . esc_html((string) get_the_title($postId)) . '</td><td>' . $link . '</td><td>'
                . self::form('seo_lab_scan', 'seo_lab_scan_' . get_current_blog_id(), [['post_id', (string) $postId]],
                    __('Scan', 'seo-lab'))
                . '</td></tr>';
        }
        echo '</tbody></table>';
        $total = (int) $query->max_num_pages;
        if ($total > 1) {
            echo '<p class="tablenav-pages">' . paginate_links([
                'base' => add_query_arg('paged', '%#%', self::pageUrl([])),
                'format' => '',
                'current' => $paged,
                'total' => $total,
            ]) . '</p>';
        }
    }

    private static function renderReport(WordPressReportArchive $archive, string $reportId, ?string $baseId): void
    {
        $report = $archive->find($reportId);
        echo '<p><a href="' . esc_url(self::pageUrl([])) . '">' . esc_html__('Back to contents', 'seo-lab') . '</a></p>';
        if ($report === null) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Report not found.', 'seo-lab') . '</p></div>';
            return;
        }
        $base = null;
        $note = null;
        if ($baseId !== null) {
            $base = $archive->find($baseId);
            if ($baseId === $reportId || $base === null || $base->postId() !== $report->postId()) {
                $base = null;
                $note = __('Comparison not available: the base report is no longer stored.', 'seo-lab');
            }
        }
        echo ReportView::render($report, $note);
        if ($base !== null) {
            self::renderComparison($base, $report);
        }
        echo '<p>';
        echo self::form('seo_lab_verify', 'seo_lab_verify_' . get_current_blog_id() . '_' . $reportId,
            [['report', $reportId]], __('Verify again', 'seo-lab'));
        echo ' ';
        echo self::form('seo_lab_export', 'seo_lab_export_' . get_current_blog_id() . '_' . $reportId,
            [['report', $reportId]], __('Export JSON', 'seo-lab'));
        echo '</p>';
    }

    private static function renderComparison(ScanReport $before, ScanReport $after): void
    {
        $rows = '';
        foreach (ReportDiff::compare($before, $after) as $diff) {
            $rows .= '<tr><td>' . esc_html($diff->ruleId()) . '</td><td>' . esc_html(self::diffLabel($diff->state())) . '</td></tr>';
        }
        echo '<h2>' . esc_html__('Comparison', 'seo-lab') . '</h2>';
        echo '<p><a href="' . esc_url(self::pageUrl(['report' => $before->scanId()])) . '">' . esc_html__('Base report', 'seo-lab')
            . '</a> &rarr; <a href="' . esc_url(self::pageUrl(['report' => $after->scanId()])) . '">' . esc_html__('New report', 'seo-lab')
            . '</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Rule', 'seo-lab') . '</th><th>'
            . esc_html__('Difference', 'seo-lab') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @param array<int, array{0: string, 1: string}> $fields
     */
    private static function form(string $action, string $nonceAction, array $fields, string $label): string
    {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        $html .= '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        $html .= '<input type="hidden" name="seo_lab_nonce" value="' . esc_attr(wp_create_nonce($nonceAction)) . '">';
        foreach ($fields as [$name, $value]) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        $html .= '<button type="submit" class="button">' . esc_html($label) . '</button></form>';
        return $html;
    }

    /**
     * @param array<string, string> $query
     */
    private static function pageUrl(array $query): string
    {
        return add_query_arg(['page' => 'seo-lab'] + $query, admin_url('tools.php'));
    }

    private static function uuidParam(string $name): ?string
    {
        $raw = $_GET[$name] ?? null;
        return is_string($raw) ? ReportActions::parseUuid($raw) : null;
    }

    private static function diffLabel(string $state): string
    {
        switch ($state) {
            case 'resolved':
                return __('Resolved', 'seo-lab');
            case 'persists':
                return __('Persists', 'seo-lab');
            case 'changed':
                return __('Changed', 'seo-lab');
            default:
                return __('Inconclusive', 'seo-lab');
        }
    }
}
