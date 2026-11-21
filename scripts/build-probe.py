#!/usr/bin/env python3
"""Genera la sonda per hosting condiviso di SEO Lab: un solo file PHP letto da STDIN con `wp eval-file -`.

Uso: python3 scripts/build-probe.py --output <file.php>

La sonda contiene la cattura del plugin (SafeHttpCapturer e le classi di src/Capture e
src/Contracts richieste per chiusura a partire da SafeHttpCapturer) nel namespace
SeoLabProbe, cosi' non collide con il plugin se fosse caricato, piu' la funzione di
esecuzione. Non usa __FILE__, __DIR__, include o require. Output deterministico: stesso
albero, stessi byte, indipendentemente da TZ e LANG. Stampa SHA-256 e dimensione.
"""

import argparse
import hashlib
import os
import re
import sys

ROOTS = ('src/Capture', 'src/Contracts')
ENTRIES = ('SeoLab\\Capture\\SafeHttpCapturer', 'SeoLab\\Capture\\SystemAddressResolver')
SOURCE_NAMESPACE = 'SeoLab'
PROBE_NAMESPACE = 'SeoLabProbe'

DECLARATION = re.compile(r'^(?:final |abstract )?(class|interface) (\w+)', re.M)
NAMESPACE_LINE = re.compile(r'^namespace ([\w\\]+);', re.M)
PARENTS = re.compile(r'^(?:final |abstract )?(?:class|interface) \w+(?: extends (\w+))?(?: implements ([\w, ]+))?', re.M)

HEADER = """<?php
/*
 * SEO Lab - sonda per hosting condiviso (file generato da scripts/build-probe.py: non modificare a mano).
 * Uso: wp eval-file - <post_id> --skip-plugins --skip-themes < seo-lab-hosting-probe.php
 * Non legge file del sito, non scrive nel database e non scrive file: l'unico file temporaneo
 * e' quello della cattura, rimosso dal capturer. Esito nel campo "outcome" del JSON su una riga.
 * Niente declare(strict_types): wp eval-file valuta il file con eval e la dichiarazione non e' ammessa.
 */
"""

PROBE_MAIN = r"""
namespace {
    /**
     * Esegue la sonda per un ID WordPress e restituisce il referto (nessun host, IP, URL, percorso,
     * corpo o header).
     *
     * @param mixed    $args      valore di $args di wp eval-file: $args[0] e' l'ID del contenuto
     * @param mixed    $resolver  risoluzione DNS di origine (AddressResolver)
     * @param callable $monotonic orologio monotono in secondi
     * @return array<string, mixed>
     */
    function seolab_probe_run($args, $resolver, $monotonic)
    {
        $report = [
            'schema' => 'seo-lab-hosting-probe',
            'schema_version' => 1,
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'context' => 'wp_eval_file_cli',
            'php' => PHP_VERSION,
            'wordpress' => isset($GLOBALS['wp_version']) && is_string($GLOBALS['wp_version']) ? $GLOBALS['wp_version'] : null,
            'libxml' => defined('LIBXML_DOTTED_VERSION') ? LIBXML_DOTTED_VERSION : null,
            'extensions' => [
                'curl' => extension_loaded('curl'),
                'dom' => extension_loaded('dom'),
                'json' => extension_loaded('json'),
                'libxml' => extension_loaded('libxml'),
                'mbstring' => extension_loaded('mbstring'),
                'openssl' => extension_loaded('openssl'),
            ],
            'not_verified' => ['web_php_runtime_transport', 'wordpress_capture_in_web_request'],
            'dns_class' => null,
        ];

        $id = is_array($args) && isset($args[0]) ? $args[0] : null;
        if ((!is_string($id) && !is_int($id)) || preg_match('/^[1-9][0-9]{0,8}\z/', (string) $id) !== 1) {
            return seolab_probe_finish($report, 'INCONCLUSIVE', 'invalid_post_id', null, null, 0);
        }
        if (!function_exists('get_post') || !function_exists('get_permalink') || !function_exists('home_url')
            || !function_exists('get_temp_dir') || !function_exists('wp_safe_remote_get')) {
            return seolab_probe_finish($report, 'INCONCLUSIVE', 'wordpress_not_loaded', null, null, 0);
        }

        try {
            $capturer = new \SeoLabProbe\Capture\SafeHttpCapturer(
                $resolver,
                $monotonic,
                rtrim((string) get_temp_dir(), '/'),
                class_exists('DOMDocument')
            );
            $snapshot = $capturer->captureForPost((int) $id);
        } catch (\Throwable $error) {
            return seolab_probe_finish($report, 'INCONCLUSIVE', 'probe_exception', null, null, 0);
        }

        $status = $snapshot->status();
        $reason = $snapshot->captureReason();
        $http = $snapshot->httpStatus();
        $redirects = count($snapshot->redirects());
        $transport = [
            'timeout', 'transport_error', 'http_status_unexpected', 'http_blocked', 'redirect_limit',
            'redirect_rejected', 'content_encoding_unsupported',
        ];
        $attempted = $http !== null || in_array($reason, $transport, true) || $status === 'partial';

        if ($status === 'ok') {
            $outcome = 'GO';
        } elseif ($attempted) {
            $outcome = 'NO-GO';
        } else {
            $outcome = 'INCONCLUSIVE';
        }

        if ($reason === 'origin_resolution_failed') {
            $report['dns_class'] = 'unresolved';
        } elseif ($reason === 'origin_not_public') {
            $report['dns_class'] = 'non_public';
        } elseif ($attempted) {
            $report['dns_class'] = 'public';
        } else {
            $report['dns_class'] = null;
        }

        return seolab_probe_finish($report, $outcome, $reason, $status, $http, $redirects);
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    function seolab_probe_finish(array $report, $outcome, $reason, $status = null, $http = null, $redirects = 0)
    {
        $report['outcome'] = $outcome;
        $report['capture_status'] = $status;
        $report['capture_reason'] = $reason;
        $report['http_status'] = $http;
        $report['redirect_count'] = (int) $redirects;
        return $report;
    }

    if (!defined('SEOLAB_PROBE_NO_RUN')) {
        $seolab_probe_report = seolab_probe_run(
            isset($args) ? $args : null,
            new \SeoLabProbe\Capture\SystemAddressResolver(),
            static function (): float {
                return microtime(true);
            }
        );
        echo json_encode($seolab_probe_report, JSON_UNESCAPED_SLASHES) . "\n";
    }
}
"""


class BuildError(Exception):
    pass


def repo_root():
    return os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def load_units(root):
    """Restituisce {fqn: {rel, ns, short, kind, text}} per le classi di ROOTS."""
    units = {}
    for base in ROOTS:
        folder = os.path.join(root, base)
        for name in sorted(os.listdir(folder)):
            if not name.endswith('.php'):
                continue
            rel = base + '/' + name
            with open(os.path.join(folder, name), encoding='utf-8') as handle:
                text = handle.read()
            ns = NAMESPACE_LINE.search(text)
            decls = DECLARATION.findall(text)
            if ns is None or len(decls) != 1:
                raise BuildError('file non riconosciuto (namespace o dichiarazione): ' + rel)
            kind, short = decls[0]
            fqn = ns.group(1) + '\\' + short
            units[fqn] = {'rel': rel, 'ns': ns.group(1), 'short': short, 'kind': kind, 'text': text}
    return units


def closure(units):
    """Chiusura delle classi referenziate a partire da ENTRIES (per FQN o per nome corto nello stesso namespace)."""
    for entry in ENTRIES:
        if entry not in units:
            raise BuildError('classe di ingresso assente: ' + entry)
    included = set()
    todo = list(ENTRIES)
    while todo:
        current = todo.pop()
        if current in included:
            continue
        included.add(current)
        text = units[current]['text']
        ns = units[current]['ns']
        for other, unit in units.items():
            if other in included or other in todo:
                continue
            literal = other in text
            same_ns_short = unit['ns'] == ns and re.search(r'(?<![\w\\])' + unit['short'] + r'\b', text)
            if literal or same_ns_short:
                todo.append(other)
    return included


def ordered(units, names):
    """Ordine di dichiarazione: ogni parent (extends/implements) prima del figlio, poi per FQN."""
    done = []
    pending = sorted(names)
    while pending:
        progressed = False
        for name in list(pending):
            match = PARENTS.search(units[name]['text'])
            parents = []
            if match:
                if match.group(1):
                    parents.append(match.group(1))
                if match.group(2):
                    parents.extend(p.strip() for p in match.group(2).split(',') if p.strip())
            resolved = []
            for parent in parents:
                for candidate in names:
                    if units[candidate]['short'] == parent and units[candidate]['ns'] == units[name]['ns']:
                        resolved.append(candidate)
                if parent in names:
                    resolved.append(parent)
            if all(p in done for p in resolved):
                done.append(name)
                pending.remove(name)
                progressed = True
        if not progressed:
            raise BuildError('dipendenza circolare tra classi della sonda')
    return done


def strip_file(text):
    """Rimuove apertura PHP, declare e namespace del file: li riscrive il generatore."""
    text = text.replace('<?php', '', 1)
    text = re.sub(r'^\s*declare\(strict_types=1\);\s*$', '', text, count=1, flags=re.M)
    text = NAMESPACE_LINE.sub('', text, count=1)
    return text.strip('\n')


def rename_namespace(text):
    return re.sub(r'(?<![A-Za-z])' + SOURCE_NAMESPACE + r'\\', PROBE_NAMESPACE + '\\\\', text)


def build(root):
    units = load_units(root)
    names = ordered(units, closure(units))
    parts = [HEADER]
    by_ns = {}
    for name in names:
        by_ns.setdefault(units[name]['ns'], []).append(name)
    for ns in sorted(by_ns):
        probe_ns = rename_namespace(ns)
        body = []
        for name in by_ns[ns]:
            body.append('// fonte: ' + units[name]['rel'])
            body.append(rename_namespace(strip_file(units[name]['text'])))
            body.append('')
        parts.append('namespace ' + probe_ns + ' {\n' + '\n'.join(body) + '}\n')
    parts.append(PROBE_MAIN)
    output = '\n'.join(parts)
    output = output.replace('\r\n', '\n')
    if any(ord(ch) > 127 for ch in output):
        raise BuildError('la sonda deve essere solo ASCII')
    if re.search(r'__FILE__|__DIR__|\binclude\b|\brequire\b', output):
        raise BuildError('la sonda non puo\' usare __FILE__, __DIR__, include o require')
    return output, names


def main(argv):
    parser = argparse.ArgumentParser(description='Genera la sonda per hosting condiviso di SEO Lab.')
    parser.add_argument('--output', required=True, help='file PHP da scrivere')
    args = parser.parse_args(argv)
    try:
        text, names = build(repo_root())
    except BuildError as error:
        print('errore: ' + str(error), file=sys.stderr)
        return 1
    data = text.encode('ascii')
    with open(args.output, 'wb') as handle:
        handle.write(data)
    print('classi incluse: ' + ', '.join(names))
    print('sha256: ' + hashlib.sha256(data).hexdigest())
    print('bytes: ' + str(len(data)))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
