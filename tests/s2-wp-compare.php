<?php

declare(strict_types=1);

/*
 * Confronto della sonda S2 su WordPress (tests/s2-wordpress.sh). Uso: php tests/s2-wp-compare.php <file-output-sonda>
 * Legge la riga "S2WP {json}" prodotta da tests/s2-wp-check.php, stampa esito e differenze per campo senza dedurne
 * la causa. Uscita 0 solo se tutte le verifiche della fase passano; ogni differenza e' riportata come tale.
 */

if (!isset($argv[1]) || !is_file($argv[1])) {
    fwrite(STDERR, "uso: php tests/s2-wp-compare.php <file-output-sonda>\n");
    exit(2);
}

$data = null;
foreach (file($argv[1], FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (strpos($line, 'S2WP ') === 0) {
        $data = json_decode(substr($line, 5), true);
    }
}
if (!is_array($data)) {
    fwrite(STDERR, "riga S2WP non trovata o JSON non valido\n");
    exit(2);
}

$failures = [];
$checks = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label . ($detail !== '' ? ' [' . $detail . ']' : '');
    }
};

$phase = (string) ($data['phase'] ?? '');

if (strpos($phase, 'window-') === 0) {
    // Finestra misurata (tests/s2-wp-window.php): zero scritture SQL e digest invariati, con controllo positivo.
    $state = substr($phase, 7);
    $expectedReason = [
        'no-yoast' => 'yoast_absent',
        'missing' => 'indexable_missing',
        'current' => 'ok',
        'obsolete' => 'indexable_obsolete',
    ][$state] ?? null;
    $pre = $data['preconditions'] ?? [];
    $check('stato noto della finestra', $expectedReason !== null, $state);
    $check('precondizioni dello stato ' . $state . ' soddisfatte', ($pre['ok'] ?? false) === true, json_encode($pre));
    $control = $data['control'] ?? [];
    $check('controllo positivo: il sensore SQL vede UPDATE e INSERT', ($control['verbs']['UPDATE'] ?? 0) >= 1
        && ($control['verbs']['INSERT'] ?? 0) >= 1, json_encode($control['verbs'] ?? []));
    $check('controllo positivo: i contatori Com_ vedono le scritture', ($control['mutating_com'] ?? 0) >= 2);
    $check('controllo positivo: il digest rileva la variazione', in_array('options', $control['changed'] ?? [], true));
    foreach (['post', 'missing_id'] as $window) {
        $run = $data['windows'][$window] ?? [];
        $result = $run['result'] ?? [];
        $check($window . ': nessuna eccezione', ($run['error'] ?? null) === null, (string) ($run['error'] ?? ''));
        $check($window . ': zero istruzioni di scrittura', ($run['mutating_statements'] ?? -1) === 0,
            json_encode($run['verbs'] ?? []));
        $check($window . ': zero scritture Com_', ($run['mutating_com'] ?? -1) === 0, json_encode($run['com_nonzero_delta'] ?? []));
        $check($window . ': nessuna tabella, opzione, riga o meta modificati', ($run['changed'] ?? ['mancante']) === [],
            implode(',', $run['changed'] ?? ['mancante']));
    }
    $post = $data['windows']['post']['result'] ?? [];
    $missingId = $data['windows']['missing_id']['result'] ?? [];
    if ($expectedReason !== null) {
        $check('motivo del post atteso: ' . $expectedReason, ($post['reason'] ?? null) === $expectedReason, (string) ($post['reason'] ?? 'nessuno'));
    }
    $check('ID inesistente: ineleggibile, nessuna lettura', ($missingId['reason'] ?? null) === 'not_eligible');
    if ($state === 'current') {
        $check('indexable corrente: canonical available con source yoast:resolved',
            ($post['canonical']['availability'] ?? null) === 'available' && ($post['canonical']['source'] ?? null) === 'yoast:resolved');
        $check('indexable corrente: robots available', ($post['robots']['availability'] ?? null) === 'available');
    } else {
        $check('stato ' . $state . ': canonical e robots unavailable', ($post['canonical']['availability'] ?? null) === 'unavailable'
            && ($post['robots']['availability'] ?? null) === 'unavailable');
    }
    $check('versione Yoast verificata', ($data['yoast_version'] ?? null) === ($state === 'no-yoast' ? null : '28.9'));
    // S4: JSON-LD atteso. Solo nel contesto admin con indexable corrente e' disponibile, con ID uguale al permalink.
    $jsonldPost = $post['jsonld'] ?? [];
    $jsonldMissingId = $missingId['jsonld'] ?? [];
    $check('JSON-LD ID inesistente: unavailable', ($jsonldMissingId['availability'] ?? null) === 'unavailable');
    if (($data['is_admin'] ?? false) === true) {
        if ($state === 'current') {
            $check('S4 admin, indexable corrente: JSON-LD available con source yoast:resolved',
                ($jsonldPost['availability'] ?? null) === 'available' && ($jsonldPost['source'] ?? null) === 'yoast:resolved',
                json_encode($jsonldPost));
            $check('S4 admin, indexable corrente: ID JSON-LD uguale al permalink',
                ($jsonldPost['value']['expected_node_ids'] ?? null) === [$data['permalink'] ?? null],
                json_encode($jsonldPost['value'] ?? null) . ' permalink=' . json_encode($data['permalink'] ?? null));
            $check('S4 admin, indexable corrente: motivo ok', ($post['jsonld_reason'] ?? null) === 'ok', (string) ($post['jsonld_reason'] ?? 'nessuno'));
        } else {
            $check('S4 admin, stato ' . $state . ': JSON-LD unavailable', ($jsonldPost['availability'] ?? null) === 'unavailable');
        }
    } else {
        $check('S4 CLI non admin, stato ' . $state . ': JSON-LD unavailable', ($jsonldPost['availability'] ?? null) === 'unavailable');
    }
    echo 'Fase: ' . $phase . ' · WP ' . ($data['wp_version'] ?? '?') . ' · PHP ' . ($data['php_version'] ?? '?')
        . ' · Yoast ' . ($data['yoast_version'] ?? 'assente') . ' · motivo post ' . ($post['reason'] ?? '?')
        . ' · scritture SQL ' . ($data['windows']['post']['mutating_statements'] ?? '?')
        . ' · scritture Com_ ' . ($data['windows']['post']['mutating_com'] ?? '?')
        . ' · controllo positivo UPDATE ' . ($control['verbs']['UPDATE'] ?? '?') . "\n";
    echo 'Verifiche: ' . $checks . ', fallite: ' . count($failures) . "\n";
    foreach ($failures as $failure) {
        echo '  - ' . $failure . "\n";
    }
    exit($failures === [] ? 0 : 1);
}
$admin = $data['expected_admin'] ?? [];
$check('utente con permessi di gestione', ($data['user_can_manage_options'] ?? false) === true);
if (substr($phase, -6) === '-admin') {
    $check('contesto admin WordPress (fase admin)', ($data['admin_context'] ?? false) === true);
} else {
    $check('contesto non admin (fase CLI senza --context=admin)', ($data['admin_context'] ?? true) === false, 'is_admin=' . json_encode($data['admin_context'] ?? null));
}
$check('provider: ID inesistente non produce atteso', ($data['expected_missing_id']['canonical']['availability'] ?? null) === 'unavailable'
    && ($data['expected_missing_id']['robots']['availability'] ?? null) === 'unavailable');
$check('capture: host di origine privato respinto da S1', ($data['capture']['status'] ?? null) === 'blocked'
    && ($data['capture']['reason'] ?? null) === 'origin_not_public', (string) ($data['capture']['reason'] ?? ''));

if ($phase === 'no-yoast') {
    $check('senza Yoast: function YoastSEO assente', ($data['yoast_function'] ?? true) === false);
    $check('senza Yoast: canonical unavailable', ($admin['canonical']['availability'] ?? null) === 'unavailable');
    $check('senza Yoast: robots unavailable', ($admin['robots']['availability'] ?? null) === 'unavailable');
    foreach (($data['outcomes'] ?? []) as $rule => $state) {
        $check('senza Yoast: nessuna anomalia confermata ' . $rule, $state !== 'confirmed_anomaly');
    }
} elseif ($phase === 'yoast' || $phase === 'yoast-admin') {
    $check('con Yoast: function YoastSEO presente', ($data['yoast_function'] ?? false) === true);
    $check('con Yoast: canonical available', ($admin['canonical']['availability'] ?? null) === 'available');
    $check('con Yoast: robots available', ($admin['robots']['availability'] ?? null) === 'available');
    if ($phase === 'yoast') {
        $check('S4 CLI non admin: JSON-LD unavailable con motivo context_not_admin',
            ($admin['jsonld']['availability'] ?? null) === 'unavailable' && ($data['jsonld_reason'] ?? null) === 'context_not_admin',
            (string) ($data['jsonld_reason'] ?? ''));
    } else {
        $check('S4 admin: contesto WordPress admin', ($data['admin_context'] ?? false) === true);
        $check('S4 admin: JSON-LD available con source yoast:resolved',
            ($admin['jsonld']['availability'] ?? null) === 'available' && ($admin['jsonld']['source'] ?? null) === 'yoast:resolved',
            json_encode($admin['jsonld'] ?? null));
        $check('S4 admin: motivo ok e nessuna guardia dei callback scattata',
            ($data['jsonld_reason'] ?? null) === 'ok' && ($data['jsonld_guard'] ?? null) === null,
            (string) ($data['jsonld_reason'] ?? '') . ' guardia=' . (string) ($data['jsonld_guard'] ?? 'nessuna'));
        $check('S4 admin: ID JSON-LD uguale al permalink',
            ($admin['jsonld']['value']['expected_node_ids'] ?? null) === [$data['permalink'] ?? null],
            json_encode($admin['jsonld']['value'] ?? null) . ' permalink=' . json_encode($data['permalink'] ?? null));
    }
    $frontend = $data['frontend'] ?? null;
    $check('frontend richiesto dalla sonda', is_array($frontend) && ($frontend['fetched'] ?? false) === true);
    if (is_array($frontend) && ($frontend['fetched'] ?? false) === true) {
        $adminCanonical = (string) ($admin['canonical']['value'] ?? '');
        $frontendCanonical = $frontend['canonical'] ?? [];
        $canonicalEqual = $adminCanonical === ''
            ? $frontendCanonical === []
            : $frontendCanonical === [$adminCanonical];
        $check('canonical admin e frontend uguali', $canonicalEqual,
            'admin=' . json_encode($adminCanonical) . ' frontend=' . json_encode($frontendCanonical));
        $adminRobots = normalize_directives($admin['robots']['value'] ?? []);
        $frontendRobots = normalize_directives($frontend['robots'] ?? []);
        $check('robots admin e frontend uguali', $adminRobots === $frontendRobots,
            'admin=' . json_encode($adminRobots) . ' frontend=' . json_encode($frontendRobots));
    }
    if ($phase === 'yoast-admin') {
        $jsonld = is_array($data['frontend'] ?? null) ? ($data['frontend']['jsonld'] ?? null) : null;
        $expectedId = $admin['jsonld']['value']['expected_node_ids'][0] ?? null;
        $check('S4 frontend: JSON-LD letto e analizzato', is_array($jsonld) && isset($jsonld['analysis']));
        if (is_array($jsonld) && isset($jsonld['analysis'])) {
            $analysis = $jsonld['analysis'];
            $check('S4 frontend: sintassi, inventario e profilo completi senza errori',
                ($analysis['syntax_complete'] ?? null) === true && ($analysis['inventory_complete'] ?? null) === true
                && ($analysis['profile_complete'] ?? null) === true && ($analysis['errors'] ?? ['x']) === [],
                json_encode($analysis));
        }
        $check('S4 frontend: ID atteso definito nel frontend dello stesso post',
            is_string($expectedId) && in_array($expectedId, $jsonld['definitions'] ?? [], true),
            'atteso=' . json_encode($expectedId) . ' definizioni=' . json_encode($jsonld['definitions'] ?? null));
        $check('S4 admin: nessun hook Yoast controllato registrato (watched_hooks vuoto)',
            ($data['watched_hooks'] ?? null) === [], json_encode($data['watched_hooks'] ?? null));
        echo 'Hook Yoast controllati (registro admin): ' . json_encode($data['watched_hooks'] ?? []) . "\n";
    }
} elseif ($phase === 'schema-off-admin') {
    $check('S4 enable_schema=false in admin: JSON-LD unavailable', ($admin['jsonld']['availability'] ?? null) === 'unavailable');
    $check('S4 enable_schema=false in admin: motivo schema_disabled', ($data['jsonld_reason'] ?? null) === 'schema_disabled',
        (string) ($data['jsonld_reason'] ?? ''));
    $check('S4 enable_schema=false: canonical e robots restano available', ($admin['canonical']['availability'] ?? null) === 'available'
        && ($admin['robots']['availability'] ?? null) === 'available');
    $check('S4 enable_schema=false in admin: nessun hook Yoast controllato registrato (watched_hooks vuoto)',
        ($data['watched_hooks'] ?? null) === [], json_encode($data['watched_hooks'] ?? null));
} else {
    $check('fase nota (no-yoast, yoast, yoast-admin o schema-off-admin)', false, $phase);
}

echo 'Fase: ' . $phase . ' · WP ' . ($data['wp_version'] ?? '?') . ' · PHP ' . ($data['php_version'] ?? '?')
    . ' · Yoast ' . ($data['yoast_version'] ?? 'assente') . "\n";
echo 'Capture: ' . ($data['capture']['status'] ?? '?') . ' / ' . ($data['capture']['reason'] ?? '-') . "\n";
foreach (($data['outcomes'] ?? []) as $rule => $state) {
    echo 'Esito ' . $rule . ': ' . $state . "\n";
}
echo 'Verifiche: ' . $checks . ', fallite: ' . count($failures) . "\n";
foreach ($failures as $failure) {
    echo '  - ' . $failure . "\n";
}
exit($failures === [] ? 0 : 1);

/**
 * Direttive robots come insieme ordinato e senza duplicati (meta robots possono riportare piu' valori separati da virgola).
 *
 * @param mixed $values
 * @return string[]
 */
function normalize_directives($values): array
{
    $out = [];
    foreach ((array) $values as $value) {
        foreach (explode(',', strtolower((string) $value)) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[$part] = true;
            }
        }
    }
    $list = array_keys($out);
    sort($list);
    return $list;
}
