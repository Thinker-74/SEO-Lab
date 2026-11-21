#!/usr/bin/env python3
"""Prova offline di scripts/build-release.py (B-1): symlink nel percorso dell'allowlist.

Uso: python3 tests/s5-release-symlink.py
Crea un albero minimo in una cartella temporanea, prova il caso valido e i casi con docs/ o
languages/ come symlink verso contenuto esterno, poi rimuove la cartella temporanea.
"""

import importlib.util
import os
import shutil
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
SPEC = importlib.util.spec_from_file_location(
    'build_release', os.path.join(HERE, '..', 'scripts', 'build-release.py'))
build_release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(build_release)

PASSED = 0
FAILED = 0


def check(ok, name):
    global PASSED, FAILED
    if ok:
        PASSED += 1
        print('OK ' + name)
    else:
        FAILED += 1
        print('FAIL ' + name)


def write(path, text='x\n'):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', encoding='utf-8') as handle:
        handle.write(text)


def minimal_tree(root):
    """Albero con i file dell'allowlist, nessun symlink."""
    write(os.path.join(root, 'seo-lab.php'), ' * License: GPL-3.0-or-later\n')
    write(os.path.join(root, 'uninstall.php'))
    write(os.path.join(root, 'README.md'))
    write(os.path.join(root, 'LICENSE'), 'GNU GENERAL PUBLIC LICENSE\nVersion 3\n')
    write(os.path.join(root, 'docs', 'compatibility.md'))
    write(os.path.join(root, 'docs', 'benchmark.md'))
    write(os.path.join(root, 'src', 'Foo.php'))
    write(os.path.join(root, 'languages', 'seo-lab.pot'))


def expect_refused(root, name):
    try:
        build_release.collect_entries(root)
    except build_release.ReleaseError:
        check(True, name)
        return
    check(False, name)


work = tempfile.mkdtemp(prefix='seo-lab-release-test-')
try:
    # Caso valido: nessun symlink, il manifest contiene i file attesi.
    good = os.path.join(work, 'good')
    minimal_tree(good)
    entries = build_release.collect_entries(good)
    check('docs/compatibility.md' in entries and 'languages/seo-lab.pot' in entries and 'src/Foo.php' in entries,
          'albero valido: docs, languages e src nel manifest')

    # docs/ come symlink verso una cartella esterna con i file dell'allowlist.
    outside = os.path.join(work, 'outside-docs')
    write(os.path.join(outside, 'compatibility.md'), 'esterno\n')
    write(os.path.join(outside, 'benchmark.md'), 'esterno\n')
    docs_link = os.path.join(work, 'docs-link')
    minimal_tree(docs_link)
    shutil.rmtree(os.path.join(docs_link, 'docs'))
    os.symlink(outside, os.path.join(docs_link, 'docs'))
    expect_refused(docs_link, 'docs/ symlink verso contenuto esterno: rifiutato')

    # languages/ come symlink verso una cartella esterna con un catalogo.
    outside_lang = os.path.join(work, 'outside-lang')
    write(os.path.join(outside_lang, 'seo-lab-it_IT.po'), 'esterno\n')
    lang_link = os.path.join(work, 'lang-link')
    minimal_tree(lang_link)
    shutil.rmtree(os.path.join(lang_link, 'languages'))
    os.symlink(outside_lang, os.path.join(lang_link, 'languages'))
    expect_refused(lang_link, 'languages/ symlink verso contenuto esterno: rifiutato')

    # Un file dell'allowlist come symlink verso un file esterno.
    file_link = os.path.join(work, 'file-link')
    minimal_tree(file_link)
    os.remove(os.path.join(file_link, 'README.md'))
    write(os.path.join(work, 'outside-readme.md'), 'esterno\n')
    os.symlink(os.path.join(work, 'outside-readme.md'), os.path.join(file_link, 'README.md'))
    expect_refused(file_link, 'README.md symlink verso file esterno: rifiutato')

    # src/ come symlink: gia' rifiutato, resta coperto.
    src_link = os.path.join(work, 'src-link')
    minimal_tree(src_link)
    shutil.rmtree(os.path.join(src_link, 'src'))
    os.symlink(os.path.join(work, 'outside-docs'), os.path.join(src_link, 'src'))
    expect_refused(src_link, 'src/ symlink: rifiutato')
finally:
    shutil.rmtree(work, ignore_errors=True)
    check(not os.path.exists(work), 'cartella temporanea rimossa')

print('PASS %d FAIL %d' % (PASSED, FAILED))
sys.exit(0 if FAILED == 0 else 1)
