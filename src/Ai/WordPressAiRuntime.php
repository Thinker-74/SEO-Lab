<?php

declare(strict_types=1);

namespace SeoLab\Ai;

/**
 * Gate del runtime WordPress Core per il modulo AI opzionale (P1.0). Non istanzia client o builder, non legge
 * credenziali o registry e non rende il modulo attivo: il chiamante decide l'opt-in, il gate risponde solo
 * sulla disponibilita'.
 */
final class WordPressAiRuntime
{
    private const MIN_VERSION = '7.0';

    /**
     * @return string disabled, unsupported_runtime, core_disabled o core_available
     */
    public static function status(bool $enabled): string
    {
        if (!$enabled) {
            return 'disabled';
        }

        global $wp_version;
        $version = is_string($wp_version) ? $wp_version : '';
        if ($version === '' || version_compare($version, self::MIN_VERSION, '<')) {
            return 'unsupported_runtime';
        }
        if (!function_exists('wp_ai_client_prompt') || !function_exists('wp_supports_ai')) {
            return 'unsupported_runtime';
        }

        return wp_supports_ai() ? 'core_available' : 'core_disabled';
    }
}
