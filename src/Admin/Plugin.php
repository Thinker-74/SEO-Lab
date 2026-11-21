<?php

declare(strict_types=1);

namespace SeoLab\Admin;

use SeoLab\Capture\SafeHttpCapturer;
use SeoLab\Capture\SystemAddressResolver;
use SeoLab\Contracts\ObservedSnapshot;
use SeoLab\Diagnosis\ScanService;
use SeoLab\Expected\YoastExpectedProvider;
use SeoLab\Storage\Cooldown;
use SeoLab\Storage\WordPressReportArchive;

/**
 * Collegamenti WordPress: hook admin, handler admin-post e composizione dei servizi per la richiesta corrente.
 * Il bootstrap aggiunge solo hook: nessuna scansione, DDL o output frontend.
 */
final class Plugin
{
    private const COOLDOWN_OPTION = 'seolab_scan_last';
    private const COOLDOWN_SECONDS = 10;
    private const PAGE = 'seo-lab';

    public static function boot(): void
    {
        add_action('init', [self::class, 'loadTextDomain']);
        if (!is_admin()) {
            return;
        }
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_seo_lab_scan', [self::class, 'handleScan']);
        add_action('admin_post_seo_lab_verify', [self::class, 'handleVerify']);
        add_action('admin_post_seo_lab_export', [self::class, 'handleExport']);
    }

    public static function loadTextDomain(): void
    {
        load_plugin_textdomain('seo-lab', false, dirname(plugin_basename(SEO_LAB_FILE)) . '/languages');
    }

    public static function menu(): void
    {
        add_management_page(
            __('SEO Lab', 'seo-lab'),
            __('SEO Lab', 'seo-lab'),
            'manage_options',
            self::PAGE,
            [AdminScreen::class, 'render']
        );
    }

    public static function handleScan(): void
    {
        self::respond(self::actions()->scan(self::method(), self::request()));
    }

    public static function handleVerify(): void
    {
        self::respond(self::actions()->verify(self::method(), self::request()));
    }

    public static function handleExport(): void
    {
        $result = self::actions()->export(self::method(), self::request());
        if (($result['ok'] ?? false) !== true) {
            self::fail((string) $result['error']);
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }

    /**
     * Servizi per la richiesta corrente. Il provider e l'archivio sono costruiti qui, mai al bootstrap.
     */
    public static function actions(): ReportActions
    {
        $archive = new WordPressReportArchive();
        $cooldown = new Cooldown(
            static function () {
                $value = get_option(self::COOLDOWN_OPTION, null);
                return is_numeric($value) ? (int) $value : null;
            },
            static function (int $now): void {
                if (!add_option(self::COOLDOWN_OPTION, $now, '', 'no')) {
                    update_option(self::COOLDOWN_OPTION, $now, 'no');
                }
            },
            self::COOLDOWN_SECONDS
        );
        $capturer = new SafeHttpCapturer(
            new SystemAddressResolver(),
            static function (): float {
                return microtime(true);
            },
            rtrim((string) get_temp_dir(), '/'),
            class_exists('DOMDocument')
        );
        $scan = new ScanService(
            [self::class, 'eligible'],
            static function () use ($cooldown): bool {
                return $cooldown->admit(time());
            },
            static function (int $postId) use ($capturer): ObservedSnapshot {
                return $capturer->captureForPost($postId);
            },
            new YoastExpectedProvider(),
            self::providerVersion(),
            static function (): string {
                return (string) wp_generate_uuid4();
            }
        );
        return new ReportActions(
            (string) get_current_blog_id(),
            static function (): bool {
                return current_user_can('manage_options');
            },
            static function (string $action, string $nonce): bool {
                return wp_verify_nonce($nonce, $action) !== false;
            },
            $archive,
            $scan,
            admin_url('tools.php?page=' . self::PAGE)
        );
    }

    /**
     * Idoneita riconvalidata sul server a ogni Scan e Verify: tipo, stato, password e permalink, senza HTTP.
     */
    public static function eligible(int $postId): bool
    {
        $post = get_post($postId);
        if (!is_object($post) || ($post->post_status ?? '') !== 'publish'
            || !in_array($post->post_type ?? '', ['post', 'page'], true)
            || ($post->post_password ?? '') !== '') {
            return false;
        }
        $permalink = get_permalink($postId);
        return is_string($permalink) && preg_match('#^https?://[^\s]+\z#', $permalink) === 1;
    }

    /**
     * Versione Yoast effettiva se leggibile e valida (es. 28.9); "none" se Yoast manca; "unavailable" altrimenti.
     */
    public static function providerVersion(): string
    {
        if (!defined('WPSEO_VERSION')) {
            return 'none';
        }
        $version = (string) constant('WPSEO_VERSION');
        return preg_match('/^\d+(\.\d+){1,2}\z/', $version) === 1 ? $version : 'unavailable';
    }

    /**
     * @return array<string, mixed>
     */
    private static function request(): array
    {
        return is_array($_POST) ? wp_unslash($_POST) : [];
    }

    private static function method(): string
    {
        return isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : '';
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function respond(array $result): void
    {
        if (($result['ok'] ?? false) === true) {
            wp_safe_redirect((string) $result['redirect']);
            exit;
        }
        self::fail((string) $result['error']);
    }

    private static function fail(string $code): void
    {
        wp_die(
            esc_html(self::message($code)),
            esc_html__('SEO Lab', 'seo-lab'),
            ['response' => self::status($code)]
        );
    }

    private static function message(string $code): string
    {
        switch ($code) {
            case 'method_not_allowed':
                return __('This action accepts only POST requests.', 'seo-lab');
            case 'forbidden':
                return __('You are not allowed to run this action.', 'seo-lab');
            case 'bad_nonce':
                return __('The security check failed. Reload the page and try again.', 'seo-lab');
            case 'not_found':
                return __('Report not found.', 'seo-lab');
            case 'not_eligible':
                return __('This content cannot be scanned: only published public posts and pages without password can be scanned.', 'seo-lab');
            case 'rate_limited':
                return __('A scan of this site ran a few seconds ago. Try again in a moment.', 'seo-lab');
            case 'storage_unavailable':
                return __('Report storage is not available on this site.', 'seo-lab');
            case 'storage_error':
                return __('The report could not be saved. Nothing was stored.', 'seo-lab');
            default:
                return __('The request is not valid.', 'seo-lab');
        }
    }

    private static function status(string $code): int
    {
        switch ($code) {
            case 'method_not_allowed':
                return 405;
            case 'forbidden':
            case 'bad_nonce':
                return 403;
            case 'not_found':
                return 404;
            case 'rate_limited':
                return 429;
            case 'storage_unavailable':
            case 'storage_error':
                return 503;
            default:
                return 400;
        }
    }
}
