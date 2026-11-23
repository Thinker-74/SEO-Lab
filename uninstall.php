<?php
/**
 * Uninstall SEO Lab: removes the report table and the plugin options of every blog.
 *
 * Deactivation keeps the data; this file runs only when an administrator deletes the plugin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$seolab_uninstall_blog = static function (): bool {
    global $wpdb;

    $prefix = (string) $wpdb->prefix;
    if (preg_match('/^[A-Za-z0-9_]{1,40}\z/', $prefix) !== 1) {
        return false;
    }

    $suppress = $wpdb->suppress_errors(true);
    $dropped = $wpdb->query('DROP TABLE IF EXISTS `' . $prefix . 'seolab_reports`');
    $wpdb->suppress_errors($suppress);
    if (false === $dropped) {
        return false;
    }

    delete_option('seolab_schema_version');
    delete_option('seolab_scan_last');
    delete_option('seolab_ai_enabled');
    delete_option('seolab_ai_ledger');

    return true;
};

$seolab_failed = false;

if (is_multisite()) {
    global $wpdb;

    $last_blog_id = 0;
    do {
        $blog_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT blog_id FROM {$wpdb->blogs} WHERE blog_id > %d ORDER BY blog_id ASC LIMIT %d",
            $last_blog_id,
            100
        ));
        // Errore di lettura, o primo batch vuoto (la rete ha sempre il sito 1): non e' una disinstallazione riuscita.
        if (!is_array($blog_ids) || $wpdb->last_error !== '' || ($last_blog_id === 0 && $blog_ids === [])) {
            $seolab_failed = true;
            break;
        }
        foreach ($blog_ids as $blog_id) {
            $last_blog_id = (int) $blog_id;
            switch_to_blog($last_blog_id);
            try {
                $done = $seolab_uninstall_blog();
            } finally {
                restore_current_blog();
            }
            if (!$done) {
                $seolab_failed = true;
            }
        }
    } while (count($blog_ids) === 100);
} elseif (!$seolab_uninstall_blog()) {
    $seolab_failed = true;
}

if ($seolab_failed) {
    wp_die(
        esc_html__('SEO Lab could not remove its report table. Some data may remain: run the uninstall again.', 'seo-lab')
    );
}
