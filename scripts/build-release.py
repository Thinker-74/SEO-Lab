#!/usr/bin/env python3
"""Costruisce lo ZIP riproducibile di SEO Lab (solo libreria standard Python).

Uso: python3 scripts/build-release.py --output <file.zip>

Il pacchetto ha radice seo-lab/ e contiene solo i file dell'allowlist. Le entry
hanno data fissa, permessi 0644, nomi ordinati e compressione STORED: due build
dello stesso albero producono gli stessi byte, indipendentemente da TZ e LANG.
Il checksum SHA-256 viene scritto in un file accanto allo ZIP, fuori dall'archivio.
"""

import argparse
import hashlib
import os
import re
import sys
import zipfile

PACKAGE_ROOT = 'seo-lab'
FIXED_DATE_TIME = (2026, 11, 21, 12, 0, 0)
FILE_MODE = 0o100644
CREATE_SYSTEM_UNIX = 3
SINGLE_FILES = (
    'seo-lab.php',
    'uninstall.php',
    'README.md',
    'LICENSE',
    'docs/compatibility.md',
    'docs/benchmark.md',
)
LANGUAGE_PREFIX = 'seo-lab'
LANGUAGE_SUFFIXES = ('.mo', '.po', '.pot')
CLASS_REFERENCE = re.compile(r'\\?SeoLab((?:\\[A-Za-z_][A-Za-z0-9_]*)+)')
SECRET_PATTERNS = (
    re.compile(r'-----BEGIN [A-Z ]*PRIVATE KEY-----'),
    re.compile(r'\bAKIA[0-9A-Z]{16}\b'),
    re.compile(r'\bsk-ant-[A-Za-z0-9_-]{10,}'),
    re.compile(r'\bghp_[A-Za-z0-9]{30,}'),
)


class ReleaseError(Exception):
    pass


def repo_root():
    return os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def require_regular_file(root, rel):
    # Ogni componente del percorso, non solo il file: docs/ o languages/ come symlink farebbero entrare contenuto esterno.
    current = root
    for part in rel.split('/'):
        current = os.path.join(current, part)
        if os.path.islink(current):
            raise ReleaseError('symlink non ammesso nel percorso: ' + rel)
    path = current
    if not os.path.isfile(path):
        raise ReleaseError('file mancante nell\'allowlist: ' + rel)
    return path


def collect_entries(root):
    """Restituisce i percorsi relativi (con /) del pacchetto, ordinati."""
    entries = set()
    for rel in SINGLE_FILES:
        require_regular_file(root, rel)
        entries.add(rel)

    src_root = os.path.join(root, 'src')
    if not os.path.isdir(src_root) or os.path.islink(src_root):
        raise ReleaseError('cartella src/ mancante o non valida')
    for dirpath, dirnames, filenames in os.walk(src_root):
        for name in dirnames:
            if os.path.islink(os.path.join(dirpath, name)):
                raise ReleaseError('symlink non ammesso in src/: ' + name)
        for name in filenames:
            full = os.path.join(dirpath, name)
            if os.path.islink(full):
                raise ReleaseError('symlink non ammesso in src/: ' + name)
            if name.endswith('.php'):
                entries.add(os.path.relpath(full, root).replace(os.sep, '/'))

    lang_root = os.path.join(root, 'languages')
    if os.path.islink(lang_root) or not os.path.isdir(lang_root):
        raise ReleaseError('cartella languages/ mancante o non valida')
    for name in os.listdir(lang_root):
        if name.startswith(LANGUAGE_PREFIX) and name.endswith(LANGUAGE_SUFFIXES):
            rel = 'languages/' + name
            require_regular_file(root, rel)
            entries.add(rel)

    for rel in entries:
        if rel.startswith('/') or '..' in rel.split('/'):
            raise ReleaseError('percorso non valido: ' + rel)
    return sorted(entries)


def check_license(root):
    with open(os.path.join(root, 'LICENSE'), encoding='utf-8') as handle:
        head = handle.read(200)
    if 'GNU GENERAL PUBLIC LICENSE' not in head or 'Version 3' not in head:
        raise ReleaseError('LICENSE non e\' GPLv3')
    with open(os.path.join(root, 'seo-lab.php'), encoding='utf-8') as handle:
        plugin_header = handle.read(800)
    if not re.search(r'^ \* License: GPL-3\.0-or-later$', plugin_header, re.M):
        raise ReleaseError('header di seo-lab.php non dichiara GPL-3.0-or-later')


def check_dependencies(root, entries):
    """Ogni classe SeoLab referenziata deve esistere nel pacchetto (o essere un namespace)."""
    package = set(entries)
    for rel in entries:
        if not rel.endswith('.php'):
            continue
        with open(os.path.join(root, rel), encoding='utf-8') as handle:
            text = handle.read()
        for match in CLASS_REFERENCE.finditer(text):
            parts = match.group(1).lstrip('\\').split('\\')
            candidate = 'src/' + '/'.join(parts) + '.php'
            namespace_dir = 'src/' + '/'.join(parts)
            if candidate in package:
                continue
            if any(p.startswith(namespace_dir + '/') for p in package):
                continue
            raise ReleaseError('dipendenza non distribuita in ' + rel + ': ' + match.group(0))


def check_secrets(root, entries):
    for rel in entries:
        with open(os.path.join(root, rel), 'rb') as handle:
            data = handle.read().decode('utf-8', errors='replace')
        for pattern in SECRET_PATTERNS:
            if pattern.search(data):
                raise ReleaseError('possibile segreto in ' + rel)


def check_provenance(root, entries):
    for rel in entries:
        with open(os.path.join(root, rel), 'rb') as handle:
            if b'seolution' in handle.read().lower():
                raise ReleaseError('marcatore legacy SEOlution in ' + rel)


def write_zip(root, entries, output):
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_STORED) as archive:
        for rel in entries:
            with open(os.path.join(root, rel), 'rb') as handle:
                data = handle.read()
            info = zipfile.ZipInfo(PACKAGE_ROOT + '/' + rel, date_time=FIXED_DATE_TIME)
            info.compress_type = zipfile.ZIP_STORED
            info.create_system = CREATE_SYSTEM_UNIX
            info.external_attr = (FILE_MODE & 0xFFFF) << 16
            archive.writestr(info, data)


def verify_zip(output, entries):
    expected = [PACKAGE_ROOT + '/' + rel for rel in entries]
    with zipfile.ZipFile(output) as archive:
        if archive.testzip() is not None:
            raise ReleaseError('ZIP corrotto')
        names = [info.filename for info in archive.infolist()]
        if names != expected:
            raise ReleaseError('manifest ZIP diverso dall\'allowlist')
        for info in archive.infolist():
            if info.date_time != FIXED_DATE_TIME or info.compress_type != zipfile.ZIP_STORED:
                raise ReleaseError('metadati non deterministici: ' + info.filename)
            if info.external_attr >> 16 != FILE_MODE:
                raise ReleaseError('permessi non 0644: ' + info.filename)
            if info.filename.endswith('/'):
                raise ReleaseError('entry di directory non ammessa: ' + info.filename)


def sha256_of(path):
    digest = hashlib.sha256()
    with open(path, 'rb') as handle:
        for chunk in iter(lambda: handle.read(65536), b''):
            digest.update(chunk)
    return digest.hexdigest()


def main(argv):
    parser = argparse.ArgumentParser(description='Costruisce lo ZIP riproducibile di SEO Lab.')
    parser.add_argument('--output', required=True, help='file ZIP di destinazione')
    args = parser.parse_args(argv)

    root = repo_root()
    output = os.path.abspath(args.output)
    try:
        check_license(root)
        entries = collect_entries(root)
        check_dependencies(root, entries)
        check_secrets(root, entries)
        check_provenance(root, entries)
        write_zip(root, entries, output)
        verify_zip(output, entries)
    except ReleaseError as error:
        sys.stderr.write('build-release: ' + str(error) + '\n')
        return 1

    checksum = sha256_of(output)
    with open(output + '.sha256', 'w', encoding='utf-8') as handle:
        handle.write(checksum + '  ' + os.path.basename(output) + '\n')
    print('build-release: ' + str(len(entries)) + ' file')
    for rel in entries:
        print('  ' + PACKAGE_ROOT + '/' + rel)
    print('sha256 ' + checksum)
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
