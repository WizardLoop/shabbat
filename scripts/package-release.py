#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
"""Build exact-commit archives with the reviewed, locked runtime source offer.

No network access, dependency updates, publication or running-bot modifications.
An optional previously reviewed source ZIP can supply dependency bytes; every
selected byte still has to match the checked-in manifest.
"""
import argparse
import datetime
import hashlib
import json
import os
import posixpath
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import tempfile
import zipfile

ROOTS = {'.github', 'src', 'migrations', 'resources', 'bin', 'scripts', 'patches',
         'docs', 'tests', 'assets', 'LICENSES'}
TOP = {'bot.php', 'composer.json', 'composer.lock', 'Dockerfile', 'docker-compose.yml',
       'LICENSE', 'VERSION', 'README.md', 'readme-he.md', 'THIRD_PARTY_NOTICES.md',
       'CHANGELOG.md', '.env.example', '.gitignore', '.dockerignore', '.gitattributes',
       '.editorconfig', 'phpunit.xml.dist', 'phpstan.neon', 'phpcs.xml.dist'}
PATTERNS = [rb'(?i)(?:BOT_TOKEN|API_HASH)\s*=\s*["\x27]?[0-9a-f]{32,}',
            rb'(?m)^-----BEGIN (?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----\r?$',
            rb'\b[0-9]{6,12}:[A-Za-z0-9_-]{30,}\b',
            rb'\b(?:gh[pousr]_[A-Za-z0-9_-]{30,}|github_pat_[A-Za-z0-9_-]{40,})\b',
            rb'\b(?:AKIA|ASIA)[A-Z0-9]{16}\b']


def require(condition, message):
    if not condition:
        raise ValueError(message)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def json_bytes(value):
    return (json.dumps(value, indent=2, ensure_ascii=False) + '\n').encode()


def scan(path, data):
    parts = PurePosixPath(path).parts
    require(path and not path.startswith('/') and '\\' not in path
            and '..' not in parts and not re.match(r'[A-Za-z]:', path)
            and str(PurePosixPath(path)) == path, 'Unsafe archive path: ' + path)
    require(not set(parts).intersection({'.git', '.env', 'node_modules', 'runtime',
            'logs', 'backups', '.phpunit.cache', '.phpstan.cache', '__pycache__', 'coverage'})
            and not re.search(r'\.(sqlite|db)(-wal|-shm)?$', path, re.I)
            and 'bot.shabbat' not in path
            and not path.endswith(('.pyc', '.madeline')),
            'Runtime/private file in archive: ' + path)
    require(not ('data' in parts and not path.startswith('tests/Fixtures/legacy/data/')),
            'Runtime data in archive: ' + path)
    require(not ('.env.' in path and not path.endswith('.env.example')),
            'Private environment file: ' + path)
    require(not any(re.search(pattern, data) for pattern in PATTERNS),
            'Credential pattern in archive: ' + path)


def git(root, *args, data=None):
    return subprocess.run(['git', '--no-replace-objects', '-C', str(root), *args], input=data,
                          capture_output=True, check=True).stdout


def application(root):
    commit = git(root, 'rev-parse', 'HEAD').decode().strip()
    require(re.fullmatch(r'[a-f0-9]{40}', commit), 'Unknown Git commit')
    require(not git(root, 'diff', '--cached', '--name-only', 'HEAD')
            and not git(root, 'ls-files', '--others', '--exclude-standard'),
            'Release packaging requires a clean committed checkout')
    records = []
    for row in git(root, 'ls-tree', '-rz', commit).split(b'\0'):
        if not row:
            continue
        head, name = row.split(b'\t', 1)
        mode, kind, oid = head.decode().split()
        path = name.decode('utf-8')
        require(kind == 'blob' and mode in ('100644', '100755'),
                'Symlink/submodule/unknown Git entry: ' + path)
        require(path in TOP or path.split('/')[0] in ROOTS, 'Unmapped source file: ' + path)
        records.append((path, oid, mode))
    blobs = git(root, 'cat-file', '--batch', data=('\n'.join(x[1] for x in records) + '\n').encode())
    app, modes, pos = {}, {}, 0
    for path, oid, mode in records:
        end = blobs.index(b'\n', pos)
        header = blobs[pos:end].split()
        require(header[0].decode() == oid and header[1] == b'blob', 'Invalid Git object response')
        size = int(header[2])
        content = blobs[end + 1:end + 1 + size]
        require((root / path).is_file() and not (root / path).is_symlink()
                and (root / path).read_bytes() == content,
                'Release packaging requires a clean committed checkout; Working bytes differ: ' + path)
        scan(path, content)
        app[path], modes[path] = content, int(mode, 8)
        pos = end + size + 2
    require(pos == len(blobs), 'Trailing Git object data')
    # ReleaseInputs scans these directories during Docker metadata verification.
    # Ignored source files must not silently change that runtime fingerprint.
    actual = {p.relative_to(root).as_posix() for directory in ROOTS
              if (root / directory).is_dir() for p in (root / directory).rglob('*') if p.is_file()}
    require(actual == {p for p in app if p.split('/')[0] in ROOTS},
            'Ignored/untracked application inputs differ from the committed tree')
    hashes = {p: sha(b) for p, b in sorted(app.items())}
    # Match ReleaseInputs::fingerprint (PHP JSON escapes slashes).
    fingerprint = sha(json.dumps(hashes, separators=(',', ':')).replace('/', '\\/').encode())
    stamp = int(git(root, 'show', '-s', '--format=%ct', commit).strip())
    return commit, app, modes, hashes, fingerprint, stamp


def dependencies(root, app, dependency_archive=None):
    scope = json.loads(app['resources/release/source-manifest.json'])
    require(scope['schema_version'] == 1, 'Unsupported source manifest schema')
    require(sha(app['composer.lock']) == scope['composer_lock_sha256'],
            'Lock changed: review and refresh the source manifest first')
    lock = json.loads(app['composer.lock'])
    locked = {p['name']: p for p in lock['packages']}
    packages = {p['name']: p for p in scope['runtime_packages']}
    require(len(packages) == len(scope['runtime_packages']) and packages.keys() == locked.keys()
            and len(packages) == scope['runtime_package_count'], 'Runtime source inventory mismatch')
    for name, package in packages.items():
        reference = locked[name].get('source', locked[name]['dist'])['reference']
        require((package['version'], package['reference'], package['license']) ==
                (locked[name]['version'], reference, locked[name]['license']),
                'Locked dependency metadata mismatch: ' + name)
    expected = scope['files_sha256']
    preferred = scope['preferred_source']
    require(all(p.startswith('additional-source/') and p in expected for p in preferred),
            'Unexpected preferred-source mapping')
    require(all(p.startswith('vendor/') or p in preferred for p in expected),
            'Unexpected dependency inventory path')
    prefixes = {'vendor/' + name + '/' for name in packages}
    require(all(p in preferred or any(p.startswith(prefix) for prefix in prefixes) for p in expected),
            'Source inventory contains an unlocked/development package')
    require(all(any(p.startswith(prefix) for p in expected) for prefix in prefixes),
            'Source inventory omits a locked runtime package')
    links = scope.get('source_symlinks', {})
    for path, link in links.items():
        require(path in expected and path.startswith('vendor/') and path not in preferred,
                'Unexpected source symlink: ' + path)
        prefix = next(prefix for prefix in prefixes if path.startswith(prefix))
        target = link['target']
        require(isinstance(target, str) and target and not target.startswith('/')
                and '\\' not in target and not re.match(r'[A-Za-z]:', target),
                'Unsafe source symlink target: ' + path)
        resolved = posixpath.normpath(posixpath.join(posixpath.dirname(path), target))
        require(resolved.startswith(prefix) and resolved in expected and resolved not in links,
                'Source symlink escapes its package or has an unreviewed target: ' + path)
        require(link['mode'] == '120777' and link['sha256'] == expected[path]
                and sha(target.encode()) == expected[path], 'Unapproved source symlink: ' + path)
    archive = zipfile.ZipFile(dependency_archive) if dependency_archive else None
    try:
        if archive:
            require(len(archive.namelist()) == len(set(archive.namelist())), 'Duplicate source ZIP entry')
        payload = {}
        for path, digest in expected.items():
            require(re.fullmatch(r'[a-f0-9]{64}', digest), 'Invalid source hash: ' + path)
            if path in preferred:
                source = preferred[path]
                require(source.startswith('resources/release/preferred-source/') and source in app,
                        'Missing committed preferred source: ' + path)
                data = app[source]
            elif archive:
                data = archive.read(path)
            else:
                source = root / path
                if source.is_symlink():
                    require(path in links, 'Unapproved symlink in runtime source: ' + path)
                    data = os.readlink(source).encode()
                else:
                    require(source.is_file(), 'Missing runtime source: ' + path)
                    data = source.read_bytes()
            require(sha(data) == digest, 'Unapproved dependency source bytes: ' + path)
            scan(path, data)
            payload[path] = data
        # Fail when a runtime package gains a file, even if no approved file changed.
        if not archive:
            omissions = scope['omitted_public_example_keys']
            omitted = {x['file']: x['sha256'] for x in omissions}
            for name in packages:
                prefix = 'vendor/' + name + '/'
                folder = root / 'vendor' / name
                actual = set()
                for file in folder.rglob('*'):
                    path = file.relative_to(root).as_posix()
                    require(not file.is_symlink() or path in links, 'Unapproved symlink in runtime source: ' + path)
                    if file.is_file() or file.is_symlink():
                        if path in omitted:
                            require(sha(file.read_bytes()) == omitted[path], 'Changed public example key: ' + path)
                        else:
                            actual.add(path)
                require(actual == {p for p in expected if p.startswith(prefix)},
                        'Runtime package has missing/unreviewed files: ' + name)
        return scope, payload
    finally:
        if archive:
            archive.close()


def write_archive(path, entries, modes, stamp):
    require(not path.exists(), 'Refusing to replace archive: ' + str(path))
    date = datetime.datetime.fromtimestamp(stamp, datetime.timezone.utc).timetuple()[:6]
    hashes = {p: sha(b) for p, b in sorted(entries.items())}
    for p, b in entries.items():
        scan(p, b)
    with zipfile.ZipFile(path, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
        for p, b in sorted(entries.items()):
            entry = zipfile.ZipInfo(p, date)
            entry.create_system = 3
            entry.external_attr = modes.get(p, 0o100755 if p.endswith('.sh') else 0o100644) << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(entry, b)
    with zipfile.ZipFile(path) as archive:
        require(len(archive.namelist()) == len(hashes) and archive.testzip() is None,
                'ZIP integrity failure')
        require({p: sha(archive.read(p)) for p in archive.namelist()} == hashes,
                'ZIP member round-trip mismatch')
    return {'artifact': path.name, 'bytes': path.stat().st_size, 'sha256': sha(path.read_bytes()),
            'files': len(hashes)}


def package(root, output, dependency_archive=None):
    commit, app, modes, hashes, fingerprint, stamp = application(root)
    version = app['VERSION'].decode().strip()
    require(re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?', version), 'Invalid VERSION')
    scope, payload = dependencies(root, app, dependency_archive)
    metadata = {'commit': commit, 'version': version, 'application_source_sha256': fingerprint,
                'local_changes': False}
    scope = dict(scope, release_commit=commit, release_tag='v' + version,
                 application_source_sha256=fingerprint, local_changes=False)
    source_text = ('# Corresponding source scope\n\n'
                   'Release v' + version + ' corresponds to application commit `' + commit + '`.\n\n'
                   'Application files match that Git tree byte for byte. The source offer includes '
                   'the locked runtime package sources, active local patches and their base files, '
                   'licenses, and the reviewed preferred-source supplements. All selected bytes are '
                   'pinned by `source-scope.json`. Reviewed upstream source symlinks retain their relative targets and Unix ZIP modes. Development dependencies and generated Composer '
                   'autoload files are excluded. This is a source offer, not an installed vendor tree.\n\n'
                   'External platform components and limitations:\n\n' +
                   '\n'.join('- ' + str(x) for x in scope['limits']) + '\n')
    names = ['shabbat-' + version + '.zip', 'shabbat-' + version + '-corresponding-source.zip',
             'RELEASE_MANIFEST.json', 'SHA256SUMS']
    output.mkdir(parents=True, exist_ok=True)
    require(not any((output / name).exists() for name in names), 'Release outputs already exist')
    with tempfile.TemporaryDirectory(prefix='.package-', dir=output) as temporary:
        temp = Path(temporary)
        application_entries = dict(app, **{'build-info.json': json_bytes(metadata)})
        source_entries = dict(application_entries, **payload,
                              **{'source-scope.json': json_bytes(scope), 'SOURCE-SCOPE.md': source_text.encode()})
        artifacts = [write_archive(temp / names[0], application_entries, modes, stamp),
                     write_archive(temp / names[1], source_entries,
                                   dict(modes, **{p: int(link['mode'], 8)
                                                  for p, link in scope.get('source_symlinks', {}).items()}), stamp)]
        manifest = {'repository': 'https://github.com/WizardLoop/shabbat', 'tag': 'v' + version,
                    'version': version, 'prerelease': '-' in version, **metadata,
                    'git_files': len(app), 'composer_lock_sha256': sha(app['composer.lock']),
                    'runtime_package_count': scope['runtime_package_count'],
                    'dependency_and_supplement_files': len(payload), 'artifacts': artifacts,
                    'scope_limits': scope['limits']}
        (temp / names[2]).write_bytes(json_bytes(manifest))
        sums = [(x['sha256'], x['artifact']) for x in artifacts]
        sums.append((sha((temp / names[2]).read_bytes()), names[2]))
        (temp / names[3]).write_bytes(''.join(h + '  ' + n + '\n' for h, n in sums).encode())
        for name in names:
            # Exclusive creation prevents replacing a parallel/reviewed release.
            with (output / name).open('xb') as target:
                target.write((temp / name).read_bytes())
    return manifest


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='Output directory (default: dist)')
    parser.add_argument('--dependency-archive', type=Path,
                        help='Source ZIP supplying only hash-pinned dependency files, for offline rebuilds')
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    result = package(root, (args.output or root / 'dist').resolve(), args.dependency_archive)
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError, KeyError, subprocess.CalledProcessError, zipfile.BadZipFile) as error:
        print('Release packaging failed: ' + str(error), file=sys.stderr)
        sys.exit(1)
