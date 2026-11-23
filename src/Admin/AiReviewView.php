<?php

declare(strict_types=1);

namespace SeoLab\Admin;

/**
 * HTML della revisione AI privata: impostazioni, anteprima, form di invio, risultato ed errori. Ogni dato, anche
 * quello del modello, passa da escaping contestuale. Le stringhe system e data sono mostrate letteralmente, con
 * codifica HTML anche per le entita' gia' presenti.
 */
final class AiReviewView
{
    /**
     * Rimuove i controlli di direzione Unicode e normalizza i separatori Unicode a spazio.
     */
    public static function sanitizeText(string $text): string
    {
        $text = (string) preg_replace('/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);
        $text = (string) preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u', ' ', $text);
        return trim($text);
    }

    public static function settings(bool $enabled, string $runtime, string $action, string $nonce, bool $saved): string
    {
        $html = '<div class="wrap"><h1>' . esc_html__('SEO Lab AI', 'seo-lab') . '</h1>';
        $html .= '<p>' . esc_html__('AI review is optional and off by default. Nothing is sent to a provider unless you turn it on and approve each request.', 'seo-lab') . '</p>';
        if ($saved) {
            $html .= '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'seo-lab') . '</p></div>';
        }
        $html .= '<p>' . esc_html(self::runtimeLabel($runtime)) . '</p>';
        $html .= '<p>' . esc_html__('Provider keys stay in WordPress Connectors. SEO Lab does not store them.', 'seo-lab') . '</p>';
        $html .= '<form method="post" action="' . esc_url($action) . '">';
        $html .= '<input type="hidden" name="action" value="seo_lab_ai_settings">';
        $html .= '<input type="hidden" name="seo_lab_nonce" value="' . esc_attr($nonce) . '">';
        $html .= '<fieldset><legend>' . esc_html__('AI review', 'seo-lab') . '</legend>';
        $html .= '<label><input type="radio" name="seolab_ai_enabled" value="0"' . ($enabled ? '' : ' checked') . '> '
            . esc_html__('Off', 'seo-lab') . '</label><br>';
        $html .= '<label><input type="radio" name="seolab_ai_enabled" value="1"' . ($enabled ? ' checked' : '') . '> '
            . esc_html__('On', 'seo-lab') . '</label>';
        $html .= '</fieldset>';
        $html .= '<p><button type="submit" class="button button-primary">' . esc_html__('Save', 'seo-lab') . '</button></p>';
        $html .= '</form></div>';
        return $html;
    }

    /**
     * Form per il singolo rapporto idoneo. Nessun valore predefinito: provider, modello e lingua si scelgono.
     *
     * @param string[] $providers provider con connettore locale configurato
     */
    public static function reviewForm(string $reportId, array $providers, string $action, string $nonce): string
    {
        $options = '<option value="">' . esc_html__('Choose a provider', 'seo-lab') . '</option>';
        foreach ($providers as $provider) {
            $options .= '<option value="' . esc_attr($provider) . '">' . esc_html(self::providerLabel($provider)) . '</option>';
        }
        $html = '<h2>' . esc_html__('Analyze with AI', 'seo-lab') . '</h2>';
        $html .= '<p>' . esc_html__('You will see the exact text sent before anything is sent.', 'seo-lab') . '</p>';
        $html .= '<form method="post" action="' . esc_url($action) . '">';
        $html .= '<input type="hidden" name="action" value="seo_lab_ai_preview">';
        $html .= '<input type="hidden" name="report" value="' . esc_attr($reportId) . '">';
        $html .= '<input type="hidden" name="seo_lab_nonce" value="' . esc_attr($nonce) . '">';
        $html .= '<p><label>' . esc_html__('Provider', 'seo-lab') . ' <select name="provider" required>' . $options . '</select></label></p>';
        $html .= '<p><label>' . esc_html__('Model ID', 'seo-lab') . ' <input type="text" name="model" required maxlength="128" '
            . 'pattern="[A-Za-z0-9][A-Za-z0-9._:-]{0,127}" value=""></label></p>';
        $html .= '<p><label>' . esc_html__('Language of the content', 'seo-lab') . ' <select name="language" required>'
            . '<option value="">' . esc_html__('Choose a language', 'seo-lab') . '</option>'
            . '<option value="it">' . esc_html__('Italian', 'seo-lab') . '</option>'
            . '<option value="en">' . esc_html__('English', 'seo-lab') . '</option>'
            . '</select></label></p>';
        $html .= '<p><button type="submit" class="button">' . esc_html__('Preview', 'seo-lab') . '</button></p>';
        $html .= '</form>';
        return $html;
    }

    /**
     * Anteprima letterale della richiesta e modulo di conferma. L'invio avviene solo con il pulsante di conferma.
     *
     * @param array<string, mixed> $p
     */
    public static function preview(array $p, string $action): string
    {
        $html = '<h2>' . esc_html__('Request preview', 'seo-lab') . '</h2>';
        $html .= '<table class="widefat striped"><tbody>';
        $html .= self::row(__('Provider', 'seo-lab'), self::providerLabel((string) $p['provider']));
        $html .= self::row(__('Model ID', 'seo-lab'), (string) $p['model']);
        $html .= self::row(__('Language', 'seo-lab'), (string) $p['language'] === 'it' ? __('Italian', 'seo-lab') : __('English', 'seo-lab'));
        $html .= self::row(__('Maximum output', 'seo-lab'), '768 ' . __('tokens', 'seo-lab'));
        $html .= self::row(__('Technical reserve', 'seo-lab'), (string) $p['units'] . ' ' . __('units (operational estimate, not the provider invoice)', 'seo-lab'));
        $html .= '</tbody></table>';
        $html .= '<h3>' . esc_html__('Instructions (system)', 'seo-lab') . '</h3>';
        $html .= '<pre>' . self::literal((string) $p['system']) . '</pre>';
        $html .= '<h3>' . esc_html__('Data (only the findings of the report, no page values)', 'seo-lab') . '</h3>';
        $html .= '<pre>' . self::literal((string) $p['data']) . '</pre>';
        $html .= '<form method="post" action="' . esc_url($action) . '">';
        $html .= '<input type="hidden" name="action" value="seo_lab_ai_confirm">';
        $html .= '<input type="hidden" name="report" value="' . esc_attr((string) $p['reportId']) . '">';
        $html .= '<input type="hidden" name="provider" value="' . esc_attr((string) $p['provider']) . '">';
        $html .= '<input type="hidden" name="model" value="' . esc_attr((string) $p['model']) . '">';
        $html .= '<input type="hidden" name="language" value="' . esc_attr((string) $p['language']) . '">';
        $html .= '<input type="hidden" name="issued_at" value="' . esc_attr((string) $p['issuedAt']) . '">';
        $html .= '<input type="hidden" name="confirmation_id" value="' . esc_attr((string) $p['confirmationId']) . '">';
        $html .= '<input type="hidden" name="seo_lab_nonce" value="' . esc_attr((string) $p['phaseNonce']) . '">';
        $html .= '<input type="hidden" name="seo_lab_ctx_nonce" value="' . esc_attr((string) $p['contextNonce']) . '">';
        $html .= '<p><button type="submit" class="button button-primary">' . esc_html__('Send a request', 'seo-lab') . '</button></p>';
        $html .= '</form>';
        return $html;
    }

    /**
     * @param array<int, array{kind: string, text: string, refs: string[]}> $items
     * @param array<string, string>                                         $labels ref -> etichetta dalla proiezione
     * @param array<string, int>|null                                       $usage  conteggi forniti dall'adapter
     */
    public static function result(array $items, array $labels, ?array $usage, string $reportUrl): string
    {
        $html = '<h2>' . esc_html__('AI review', 'seo-lab') . '</h2>';
        $html .= '<p>' . esc_html__('This text was produced by an AI model. Check it against the report before acting.', 'seo-lab') . '</p>';
        foreach ($items as $item) {
            // Testo del modello: doppia codifica, cosi' un'entita' come &#x202E; resta testo e non diventa controllo.
            $html .= '<h3>' . esc_html(self::kindLabel($item['kind'])) . '</h3>';
            $html .= '<p>' . self::literal($item['text']) . '</p>';
            $html .= '<ul>';
            foreach ($item['refs'] as $ref) {
                $html .= '<li>' . self::literal($labels[$ref] ?? $ref) . '</li>';
            }
            $html .= '</ul>';
        }
        if ($usage !== null) {
            $html .= '<p>' . esc_html(__('Tokens reported by the provider', 'seo-lab')) . ': '
                . esc_html(self::usageText($usage)) . '</p>';
        }
        $html .= '<p><a href="' . esc_url($reportUrl) . '">' . esc_html__('Back to the report', 'seo-lab') . '</a></p>';
        return $html;
    }

    public static function error(string $code, string $reportUrl): string
    {
        $html = '<p>' . esc_html(self::message($code)) . '</p>';
        $html .= '<p><a href="' . esc_url($reportUrl) . '">' . esc_html__('Back', 'seo-lab') . '</a></p>';
        return $html;
    }

    public static function page(string $title, string $body): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<title>' . esc_html($title) . '</title></head><body><div class="wrap">' . $body . '</div></body></html>';
    }

    public static function message(string $code): string
    {
        switch ($code) {
            case 'method_not_allowed':
                return __('This action accepts only POST requests.', 'seo-lab');
            case 'forbidden':
                return __('You are not allowed to run this action.', 'seo-lab');
            case 'bad_nonce':
                return __('The security check failed. Reload the page and try again.', 'seo-lab');
            case 'bad_request':
                return __('The request is not valid.', 'seo-lab');
            case 'not_found':
                return __('Report not found.', 'seo-lab');
            case 'preview_required':
                return __('The request changed or expired. Preview it again before sending.', 'seo-lab');
            case 'not_reviewable':
                return __('This report cannot be reviewed: it has no findings or the content is no longer eligible.', 'seo-lab');
            case 'disabled':
                return __('AI review is off on this site.', 'seo-lab');
            case 'runtime_unavailable':
                return __('This WordPress version does not offer AI review. Only the standard SEO Lab reports are available.', 'seo-lab');
            case 'unconfigured':
                return __('The provider is not configured in WordPress Connectors.', 'seo-lab');
            case 'rate_limited':
                return __('Too many AI requests in a short time. Try again later.', 'seo-lab');
            case 'budget_exhausted':
                return __('The daily AI limit of this site is reached. Nothing was sent.', 'seo-lab');
            case 'timeout':
            case 'provider_error':
            case 'model_unavailable':
            case 'core_disabled':
            case 'unsupported_runtime':
            case 'invalid_input':
                return __('The AI provider cannot answer now. Nothing was stored.', 'seo-lab');
            case 'invalid_response':
                return __('The AI answer was not valid and was not shown.', 'seo-lab');
            default:
                return __('The AI review could not be completed.', 'seo-lab');
        }
    }

    private static function literal(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
    }

    private static function row(string $label, string $value): string
    {
        return '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private static function kindLabel(string $kind): string
    {
        switch ($kind) {
            case 'explanation':
                return __('Explanation', 'seo-lab');
            case 'hypothesis':
                return __('Hypothesis', 'seo-lab');
            default:
                return __('Suggested test', 'seo-lab');
        }
    }

    private static function providerLabel(string $provider): string
    {
        return $provider === 'anthropic' ? 'Anthropic' : 'OpenAI';
    }

    private static function runtimeLabel(string $runtime): string
    {
        switch ($runtime) {
            case 'core_available':
                return __('WordPress AI is available on this site.', 'seo-lab');
            case 'core_disabled':
                return __('WordPress AI is turned off on this site: AI review cannot run.', 'seo-lab');
            default:
                return __('This WordPress version does not offer AI review. SEO Lab works as usual.', 'seo-lab');
        }
    }

    /**
     * @param array<string, int> $usage
     */
    private static function usageText(array $usage): string
    {
        $parts = [];
        if (isset($usage['input_tokens'])) {
            $parts[] = 'input ' . $usage['input_tokens'];
        }
        if (isset($usage['output_tokens'])) {
            $parts[] = 'output ' . $usage['output_tokens'];
        }
        return implode(', ', $parts);
    }
}
