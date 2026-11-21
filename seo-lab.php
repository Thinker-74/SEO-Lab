<?php
/**
 * Plugin Name: SEO Lab
 * Description: Read-only SEO output debugger: compares the expected Yoast values with the public output of a page.
 * Version: 0.2.0
 * License: GPL-3.0-or-later
 * Text Domain: seo-lab
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SEO_LAB_FILE', __FILE__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'SeoLab\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

\SeoLab\Admin\Plugin::boot();
