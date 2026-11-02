#!/usr/bin/env python3
"""Existing-install WP identity and required effects. Invoked via qualified SSH stdin."""
import json
import re
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlsplit

SLUG = 'products-manager'


def site(value):
    parsed = urlsplit(value)
    if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in ('', '/'):
        raise RuntimeError('An exact HTTPS site origin is required')
    return value.rstrip('/')


def layout(value):
    raw = value.rstrip('/')
    base = Path(raw)
    if not base.is_absolute() or str(base) != raw or any(part in ('.', '..') for part in raw.split('/')) or base.parts[-2:] != ('wp-content', 'plugins'):
        raise RuntimeError('Unsupported configured plugin-base layout')
    root = base.parent.parent
    plugin = base / SLUG
    if root.name != 'public':
        raise RuntimeError('Only the qualified public/wp-content/plugins layout is supported')
    for directory in (root, root / 'wp-content', base, plugin):
        if not directory.is_dir() or directory.resolve(strict=True) != directory or directory.is_symlink():
            raise RuntimeError('Existing canonical WP/plugin directories are required; no first install')
    for filename in ('wp-load.php', 'wp-config.php'):
        if not (root / filename).is_file():
            raise RuntimeError('Missing WordPress root file')
    if not (plugin / 'products-manager.php').is_file():
        raise RuntimeError('Existing plugin entry file is required')
    return root, base, plugin


def wp(root, *arguments):
    result = subprocess.run(['wp', '--path=' + str(root), '--skip-plugins', '--skip-themes', '--no-color', *arguments], text=True, capture_output=True, timeout=120)
    if result.returncode:
        raise RuntimeError('Required WP command failed: ' + ' '.join(arguments) + '; exit=' + str(result.returncode))
    return result.stdout.strip()


def guard(base_value, expected_site, environment, expected_version):
    if environment not in ('staging', 'production'):
        raise RuntimeError('Unsupported target environment')
    expected_site = site(expected_site)
    root, base, plugin = layout(base_value)
    wp(root, 'core', 'is-installed')
    code = "echo json_encode(['ABSPATH'=>rtrim(ABSPATH,'/'),'content'=>rtrim(WP_CONTENT_DIR,'/'),'plugins'=>rtrim(WP_PLUGIN_DIR,'/'),'home'=>get_option('home'),'environment'=>wp_get_environment_type()]);"
    data = json.loads(wp(root, 'eval', code))
    if data.get('ABSPATH') != str(root) or data.get('content') != str(root / 'wp-content') or data.get('plugins') != str(base) or site(data.get('home', '')) != expected_site or data.get('environment') != environment:
        raise RuntimeError('Actual WP root/content/site/environment differs from the reviewed target')
    wp(root, 'plugin', 'is-active', SLUG)
    version = wp(root, 'plugin', 'get', SLUG, '--field=version')
    if expected_version and version != expected_version:
        raise RuntimeError('Existing plugin version differs from expected baseline')
    return root, {'rootMatched': True, 'pluginPathMatched': True, 'siteMatched': True, 'environmentMatched': True, 'alreadyActive': True, 'version': version}


def perform(mode, base, expected_site, environment, expected_version):
    root, receipt = guard(base, expected_site, environment, expected_version)
    if mode == 'preflight':
        receipt['method'] = 'core/MU/bootstrap-limited reads; no operator-requested mutation, not a universal zero-SQL assertion'
        return receipt
    if mode != 'effects':
        raise RuntimeError('Unknown bounded remote mode')
    # Activation is idempotent only: guard() must prove already active first.
    wp(root, 'plugin', 'activate', SLUG)
    wp(root, 'plugin', 'is-active', SLUG)
    wp(root, 'cache', 'flush')
    wp(root, 'kinsta', 'cache', 'purge', '--all')
    if wp(root, 'plugin', 'get', SLUG, '--field=version') != expected_version:
        raise RuntimeError('Post-effect plugin version readback differs')
    receipt['effects'] = ['already-active-activation', 'active-readback', 'object-cache-flush', 'kinsta-page-cache-purge', 'version-readback']
    return receipt


if __name__ == '__main__':
    try:
        if len(sys.argv) != 6:
            raise RuntimeError('Five exact remote arguments required')
        print(json.dumps(perform(*sys.argv[1:]), sort_keys=True))
    except Exception as error:
        print(json.dumps({'status': 'refused_or_required_effect_failed', 'error': str(error), 'mode': sys.argv[1] if len(sys.argv) > 1 else 'invalid'}), file=sys.stderr)
        raise SystemExit(1)
