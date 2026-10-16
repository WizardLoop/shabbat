#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
"""Offline regression checks for the exact-commit release packager.

Fixtures are disposable Git repositories. No network, bot, vendor installation,
or production data is accessed.
"""
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
import warnings
import zipfile

sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location(
    "shabbat_release_packager",
    Path(__file__).resolve().parents[1] / "scripts" / "package-release.py",
)
PACKAGER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PACKAGER)
STAMP = 946684800
RUNTIME = "vendor/example/library/"
SUPPLEMENT = "additional-source/example-builder/LICENSE"
PREFERRED = "resources/release/preferred-source/example-builder/LICENSE"


def digest(data):
    return hashlib.sha256(data).hexdigest()


def encode(value):
    return (json.dumps(value, indent=2) + "\n").encode()


def run_git(root, *arguments):
    environment = dict(os.environ)
    environment.update({
        "GIT_AUTHOR_DATE": "2000-01-01T00:00:00+00:00",
        "GIT_COMMITTER_DATE": "2000-01-01T00:00:00+00:00",
        "GIT_TERMINAL_PROMPT": "0",
    })
    return subprocess.run(
        ["git", "-C", str(root), *arguments], check=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=environment,
    ).stdout


class ReleasePackagingTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if shutil.which("git") is None:
            raise RuntimeError("Git is required for the release packaging regression suite")
        cls.fixture_directory = tempfile.TemporaryDirectory(prefix="shabbat-release-fixture-")
        cls.template = Path(cls.fixture_directory.name) / "repository"
        cls.template.mkdir()
        package = {
            "name": "example/library", "version": "v1.0.0", "license": ["MIT"],
            "source": {"reference": "a" * 40},
            "dist": {"reference": "a" * 40},
        }
        lock = encode({
            "packages": [package],
            "packages-dev": [{
                "name": "example/dev-tool", "version": "v2.0.0",
                "license": ["MIT"], "dist": {"reference": "b" * 40},
            }],
        })
        cls.runtime = {
            RUNTIME + "LICENSE": b"MIT fixture attribution\n",
            RUNTIME + "src/Runtime.php": b"<?php\n// Runtime fixture.\n",
        }
        cls.supplement = b"MIT builder source attribution\n"
        cls.scope = {
            "schema_version": 1,
            "composer_lock_sha256": digest(lock),
            "runtime_packages": [{
                "name": package["name"], "version": package["version"],
                "reference": package["source"]["reference"], "license": package["license"],
            }],
            "runtime_package_count": 1,
            "files_sha256": {
                **{path: digest(data) for path, data in cls.runtime.items()},
                SUPPLEMENT: digest(cls.supplement),
            },
            "preferred_source": {SUPPLEMENT: PREFERRED},
            "omitted_public_example_keys": [],
            "limits": ["Fixture excludes platform components and development tools."],
        }
        cls.application_files = {
            ".gitignore": b"/vendor/\n/dist/\n",
            ".gitattributes": b"* text eol=lf\nmigrations/** -text\n",
            "VERSION": b"3.0.0\n",
            "composer.json": b'{"license":"AGPL-3.0-only"}\n',
            "composer.lock": lock,
            "LICENSE": b"Application fixture license\n",
            "README.md": b"Release packaging fixture.\n",
            "migrations/001_bytes.sql": b"CREATE TABLE example (id INTEGER);\r\n",
            "scripts/example.sh": b"#!/bin/sh\nexit 0\n",
            "resources/templates/example.json": b'{"message":"hello"}\n',
            PREFERRED: cls.supplement,
            "resources/release/source-manifest.json": encode(cls.scope),
        }
        for name, data in {**cls.application_files, **cls.runtime}.items():
            path = cls.template / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        run_git(cls.template, "init", "--quiet")
        for key, value in (
            ("user.name", "Release Fixture"), ("user.email", "fixture@example.invalid"),
            ("core.autocrlf", "false"), ("core.filemode", "false"),
            ("commit.gpgsign", "false"), ("core.hooksPath", str(cls.template / ".no-hooks")),
        ):
            run_git(cls.template, "config", key, value)
        run_git(cls.template, "add", ".")
        run_git(cls.template, "update-index", "--chmod=+x", "scripts/example.sh")
        run_git(cls.template, "commit", "--quiet", "-m", "Deterministic release fixture")

    @classmethod
    def tearDownClass(cls):
        cls.fixture_directory.cleanup()

    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="shabbat-release-test-")
        self.addCleanup(self.directory.cleanup)
        self.base = Path(self.directory.name)
        self.root = self.base / "repository"
        shutil.copytree(self.template, self.root)
        self.app = dict(self.application_files)
        self.scope_copy = copy.deepcopy(self.scope)

    def set_scope(self):
        self.app["resources/release/source-manifest.json"] = encode(self.scope_copy)

    def write(self, relative, data):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)

    def source_symlink_fixture(self, native=False):
        path = RUNTIME + "docs/index.md"
        target = "../README.md"
        readme = RUNTIME + "README.md"
        pointer = target.encode("utf-8")
        self.write(readme, b"Reviewed runtime documentation.\n")
        self.scope_copy["files_sha256"][readme] = digest((self.root / readme).read_bytes())
        self.scope_copy["files_sha256"][path] = digest(pointer)
        self.scope_copy["source_symlinks"] = {
            path: {"target": target, "mode": "120777", "sha256": digest(pointer)},
        }
        self.set_scope()
        destination = self.root / path
        destination.parent.mkdir(parents=True, exist_ok=True)
        if native:
            try:
                os.symlink(target, destination)
            except OSError as error:
                if os.name == "nt" and (
                    getattr(error, "winerror", None) in (50, 1314)
                    or error.errno in (1, 13, 38)
                ):
                    self.skipTest(
                        "Windows runner cannot create native symlinks; "
                        "the pointer-file and ZIP-mode cases still run: " + str(error)
                    )
                raise
        else:
            destination.write_bytes(pointer)
        return path, target, readme

    def source_zip(self, entries=None, duplicate=None):
        path = self.base / "dependency-input.zip"
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", UserWarning)
            with zipfile.ZipFile(path, "w") as archive:
                for name, data in (self.runtime if entries is None else entries).items():
                    archive.writestr(name, data)
                if duplicate is not None:
                    archive.writestr(duplicate, self.runtime[duplicate])
        return path

    def test_clean_commit_preserves_crlf_modes_and_fingerprint(self):
        commit, app, modes, hashes, fingerprint, stamp = PACKAGER.application(self.root)
        self.assertEqual(self.application_files, app)
        self.assertEqual(40, len(commit))
        self.assertEqual(STAMP, stamp)
        self.assertEqual(0o100755, modes["scripts/example.sh"])
        self.assertTrue(app["migrations/001_bytes.sql"].endswith(b"\r\n"))
        expected = {path: digest(data) for path, data in sorted(app.items())}
        self.assertEqual(expected, hashes)
        self.assertEqual(
            digest(json.dumps(expected, separators=(",", ":")).replace("/", "\\/").encode()),
            fingerprint,
        )

    def test_tracked_changes_are_rejected(self):
        self.write("README.md", b"uncommitted edit\n")
        with self.assertRaisesRegex(ValueError, "clean committed checkout"):
            PACKAGER.application(self.root)

    def test_untracked_source_is_rejected(self):
        self.write("src/Untracked.php", b"<?php\n")
        with self.assertRaisesRegex(ValueError, "clean committed checkout"):
            PACKAGER.application(self.root)

    def test_hidden_worktree_byte_mismatch_is_rejected(self):
        run_git(self.root, "update-index", "--assume-unchanged", "migrations/001_bytes.sql")
        self.write("migrations/001_bytes.sql", self.app["migrations/001_bytes.sql"].replace(b"\r\n", b"\n"))
        self.assertEqual(b"", run_git(self.root, "status", "--porcelain=v1", "--untracked-files=all"))
        with self.assertRaisesRegex(ValueError, "Working bytes differ"):
            PACKAGER.application(self.root)

    def test_git_replacement_cannot_mix_commit_identity_and_source(self):
        original = run_git(self.root, "rev-parse", "HEAD").decode().strip()
        replacement_readme = b"Replacement commit source must not use the original identity.\n"
        self.write("README.md", replacement_readme)
        run_git(self.root, "add", "README.md")
        run_git(self.root, "commit", "--quiet", "-m", "Replacement source fixture")
        replacement = run_git(self.root, "rev-parse", "HEAD").decode().strip()
        run_git(self.root, "reset", "--soft", original)
        run_git(self.root, "replace", original, replacement)

        # Ordinary Git now reports identity A with tree/index/worktree B as clean.
        self.assertEqual(original, run_git(self.root, "rev-parse", "HEAD").decode().strip())
        self.assertEqual(replacement_readme, run_git(self.root, "show", "HEAD:README.md"))
        self.assertEqual(b"", run_git(self.root, "status", "--porcelain=v1", "--untracked-files=all"))
        output = self.base / "replacement-output"
        with self.assertRaisesRegex(ValueError, "clean committed checkout"):
            PACKAGER.package(self.root, output)
        self.assertFalse(output.exists())

        # Restoring the actual A tree must package A bytes, despite the local ref.
        run_git(self.root, "--no-replace-objects", "reset", "--hard", original)
        self.assertEqual(replacement_readme, run_git(self.root, "show", "HEAD:README.md"))
        manifest = PACKAGER.package(self.root, output)
        self.assertEqual(original, manifest["commit"])
        with zipfile.ZipFile(output / "shabbat-3.0.0.zip") as archive:
            self.assertEqual(self.app["README.md"], archive.read("README.md"))
            metadata = json.loads(archive.read("build-info.json"))
            self.assertEqual(original, metadata["commit"])
            self.assertEqual(manifest["application_source_sha256"], metadata["application_source_sha256"])

    def test_ignored_source_input_is_rejected(self):
        self.write("src/ignored.php", b"<?php // ignored source\n")
        with (self.root / ".git/info/exclude").open("a", encoding="utf-8") as exclude:
            exclude.write("\n/src/ignored.php\n")
        self.assertEqual(b"", run_git(self.root, "status", "--porcelain=v1", "--untracked-files=all"))
        with self.assertRaisesRegex(ValueError, "Ignored/untracked application inputs"):
            PACKAGER.application(self.root)

    def test_unmapped_committed_source_is_rejected(self):
        self.write("private-notes.txt", b"not an approved source root\n")
        run_git(self.root, "add", "private-notes.txt")
        run_git(self.root, "commit", "--quiet", "-m", "Add unmapped file")
        with self.assertRaisesRegex(ValueError, "Unmapped source file"):
            PACKAGER.application(self.root)

    def test_committed_credentials_are_rejected(self):
        self.write("src/example.php", b"<?php // " + b"123456789:" + b"Z" * 35)
        run_git(self.root, "add", "src/example.php")
        run_git(self.root, "commit", "--quiet", "-m", "Credential pattern fixture")
        with self.assertRaisesRegex(ValueError, "Credential pattern"):
            PACKAGER.application(self.root)

    def test_unsafe_and_private_archive_paths_are_rejected(self):
        paths = (
            "/absolute", "../escape", "src/../escape", "src//double",
            "src/./dot", "C:/drive", "src\\windows", ".git/config", ".env",
            "resources/.env.production", "runtime/session", "backups/archive",
            "logs/run.log", "data/state.json", "src/state.sqlite-wal",
            "src/state.db-shm", "src/bot.shabbat", "src/session.madeline",
            "scripts/__pycache__/package.pyc", "coverage/index.html",
        )
        for path in paths:
            with self.subTest(path=path), self.assertRaises(ValueError):
                PACKAGER.scan(path, b"fixture")

    def test_public_examples_and_required_resources_are_allowed(self):
        for path in (
            ".env.example", "resources/templates/example.json",
            "tests/Fixtures/legacy/data/settings.json", "patches/example.baseline",
            "resources/source.txt",
        ):
            with self.subTest(path=path):
                PACKAGER.scan(path, b"public fixture")

    def test_credentials_and_private_keys_are_rejected(self):
        patterns = (
            b"BOT_TOKEN=" + b"a" * 32,
            b"API_HASH=" + b"b" * 32,
            b"123456789:" + b"x" * 35,
            b"ghp_" + b"x" * 35,
            b"ghs_" + b"x-y_" * 15,
            b"github_pat_" + b"x" * 45,
            b"AKIA" + b"A" * 16,
            b"-----BEGIN " + b"PRIVATE KEY-----\nfixture\n",
        )
        for index, content in enumerate(patterns):
            with self.subTest(index=index), self.assertRaisesRegex(ValueError, "Credential"):
                PACKAGER.scan("src/example.txt", content)

    def test_runtime_and_committed_preferred_source_are_selected(self):
        scope, payload = PACKAGER.dependencies(self.root, self.app)
        self.assertEqual(1, scope["runtime_package_count"])
        self.assertEqual({**self.runtime, SUPPLEMENT: self.supplement}, payload)

    def test_changed_lock_is_rejected(self):
        self.app["composer.lock"] += b"\n"
        with self.assertRaisesRegex(ValueError, "Lock changed"):
            PACKAGER.dependencies(self.root, self.app)

    def test_runtime_metadata_version_reference_license_are_enforced(self):
        for field, invalid in (("version", "v9.0.0"), ("reference", "c" * 40), ("license", ["GPL-2.0-only"])):
            with self.subTest(field=field):
                self.scope_copy = copy.deepcopy(self.scope)
                self.scope_copy["runtime_packages"][0][field] = invalid
                self.set_scope()
                with self.assertRaisesRegex(ValueError, "Locked dependency metadata mismatch"):
                    PACKAGER.dependencies(self.root, self.app)

    def test_runtime_package_inventory_is_exact(self):
        variants = ([], self.scope["runtime_packages"] * 2, [{
            **self.scope["runtime_packages"][0], "name": "example/unlocked",
        }])
        for packages in variants:
            with self.subTest(packages=packages):
                self.scope_copy = copy.deepcopy(self.scope)
                self.scope_copy["runtime_packages"] = packages
                self.set_scope()
                with self.assertRaisesRegex(ValueError, "Runtime source inventory mismatch"):
                    PACKAGER.dependencies(self.root, self.app)

    def test_malformed_source_hash_is_rejected(self):
        self.scope_copy["files_sha256"][RUNTIME + "LICENSE"] = "not-a-hash"
        self.set_scope()
        with self.assertRaisesRegex(ValueError, "Invalid source hash"):
            PACKAGER.dependencies(self.root, self.app)

    def test_changed_runtime_bytes_are_rejected(self):
        self.write(RUNTIME + "LICENSE", b"modified license\n")
        with self.assertRaisesRegex(ValueError, "Unapproved dependency"):
            PACKAGER.dependencies(self.root, self.app)

    def test_missing_runtime_file_is_rejected(self):
        (self.root / RUNTIME / "LICENSE").unlink()
        with self.assertRaisesRegex(ValueError, "Missing runtime source"):
            PACKAGER.dependencies(self.root, self.app)

    def test_unreviewed_runtime_file_is_rejected(self):
        self.write(RUNTIME + "unreviewed.php", b"<?php\n")
        with self.assertRaisesRegex(ValueError, "missing/unreviewed"):
            PACKAGER.dependencies(self.root, self.app)

    def test_manifest_cannot_add_unlocked_vendor_package(self):
        path, content = "vendor/example/dev-tool/tool.phar", b"unlocked development fixture"
        self.write(path, content)
        self.scope_copy["files_sha256"][path] = digest(content)
        self.set_scope()
        with self.assertRaises(ValueError):
            PACKAGER.dependencies(self.root, self.app)

    def test_manifest_cannot_claim_package_without_source_files(self):
        self.scope_copy["files_sha256"] = {SUPPLEMENT: digest(self.supplement)}
        self.set_scope()
        shutil.rmtree(self.root / RUNTIME)
        with self.assertRaises(ValueError):
            PACKAGER.dependencies(self.root, self.app)

    def test_missing_committed_preferred_source_is_rejected(self):
        del self.app[PREFERRED]
        with self.assertRaisesRegex(ValueError, "Missing committed preferred source"):
            PACKAGER.dependencies(self.root, self.app)

    def test_preferred_source_mapping_must_use_committed_supplement_root(self):
        self.scope_copy["preferred_source"][SUPPLEMENT] = "README.md"
        self.set_scope()
        with self.assertRaisesRegex(ValueError, "Missing committed preferred source"):
            PACKAGER.dependencies(self.root, self.app)

    def test_preferred_source_hash_is_enforced(self):
        self.app[PREFERRED] = b"changed builder source"
        with self.assertRaisesRegex(ValueError, "Unapproved dependency"):
            PACKAGER.dependencies(self.root, self.app)

    def test_reviewed_example_key_omission_is_exact(self):
        path, content = RUNTIME + "examples/public-key.pem", b"public example fixture"
        self.write(path, content)
        self.scope_copy["omitted_public_example_keys"] = [{"file": path, "sha256": digest(content)}]
        self.set_scope()
        _, payload = PACKAGER.dependencies(self.root, self.app)
        self.assertNotIn(path, payload)
        self.write(path, b"changed example")
        with self.assertRaisesRegex(ValueError, "Changed public example key"):
            PACKAGER.dependencies(self.root, self.app)

    def test_source_symlink_approved_native_preserves_pointer_bytes(self):
        path, target, readme = self.source_symlink_fixture(native=True)
        self.assertTrue((self.root / path).is_symlink())
        scope, payload = PACKAGER.dependencies(self.root, self.app)
        self.assertEqual(target.encode("utf-8"), payload[path])
        self.assertNotEqual(payload[readme], payload[path])
        self.assertEqual("120777", scope["source_symlinks"][path]["mode"])

    def test_source_symlink_pointer_fallback_preserves_zip_type_and_target(self):
        path, target, readme = self.source_symlink_fixture()
        self.assertFalse((self.root / path).is_symlink())
        _, payload = PACKAGER.dependencies(self.root, self.app)
        self.assertEqual(target.encode("utf-8"), payload[path])
        self.write("resources/release/source-manifest.json", self.app["resources/release/source-manifest.json"])
        run_git(self.root, "add", "resources/release/source-manifest.json")
        run_git(self.root, "commit", "--quiet", "-m", "Approve fixture source symlink")
        output = self.base / "symlink-output"
        PACKAGER.package(self.root, output)
        with zipfile.ZipFile(output / "shabbat-3.0.0-corresponding-source.zip") as archive:
            self.assertEqual(3, archive.getinfo(path).create_system)
            self.assertEqual(0o120777, archive.getinfo(path).external_attr >> 16)
            self.assertEqual(target.encode("utf-8"), archive.read(path))
            self.assertEqual((self.root / readme).read_bytes(), archive.read(readme))
            scope = json.loads(archive.read("source-scope.json"))
            self.assertEqual(scope["files_sha256"][path], digest(archive.read(path)))
        with zipfile.ZipFile(output / "shabbat-3.0.0.zip") as archive:
            self.assertNotIn(path, archive.namelist())

    def test_source_symlink_unapproved_native_is_rejected(self):
        self.source_symlink_fixture(native=True)
        self.scope_copy.pop("source_symlinks")
        self.set_scope()
        with self.assertRaisesRegex(ValueError, "Unapproved symlink"):
            PACKAGER.dependencies(self.root, self.app)

    def test_source_symlink_outside_package_and_absolute_targets_are_rejected(self):
        path, _, _ = self.source_symlink_fixture()
        for target in ("../../outside.php", "/outside.php", "C:/outside.php", "..\\outside.php"):
            with self.subTest(target=target):
                pointer = target.encode("utf-8")
                self.scope_copy["files_sha256"][path] = digest(pointer)
                self.scope_copy["source_symlinks"][path] = {
                    "target": target, "mode": "120777", "sha256": digest(pointer),
                }
                self.write(path, pointer)
                self.set_scope()
                with self.assertRaisesRegex(ValueError, "Unsafe source symlink|Source symlink escapes"):
                    PACKAGER.dependencies(self.root, self.app)

    def test_source_symlink_changed_native_target_is_rejected(self):
        path, _, _ = self.source_symlink_fixture(native=True)
        (self.root / path).unlink()
        os.symlink("../LICENSE", self.root / path)
        with self.assertRaisesRegex(ValueError, "Unapproved dependency source bytes"):
            PACKAGER.dependencies(self.root, self.app)

    def test_duplicate_input_zip_entry_is_rejected(self):
        path = self.source_zip(duplicate=RUNTIME + "LICENSE")
        with self.assertRaisesRegex(ValueError, "Duplicate source ZIP entry"):
            PACKAGER.dependencies(self.root, self.app, path)

    def test_missing_input_zip_file_is_rejected(self):
        path = self.source_zip({RUNTIME + "LICENSE": self.runtime[RUNTIME + "LICENSE"]})
        with self.assertRaises((ValueError, KeyError)):
            PACKAGER.dependencies(self.root, self.app, path)

    def test_unapproved_input_zip_bytes_are_rejected(self):
        entries = {**self.runtime, RUNTIME + "LICENSE": b"wrong bytes"}
        with self.assertRaisesRegex(ValueError, "Unapproved dependency"):
            PACKAGER.dependencies(self.root, self.app, self.source_zip(entries))

    def test_archive_cannot_override_committed_preferred_source(self):
        path = self.source_zip({**self.runtime, SUPPLEMENT: b"untrusted supplement"})
        _, payload = PACKAGER.dependencies(self.root, self.app, path)
        self.assertEqual(self.supplement, payload[SUPPLEMENT])

    def test_unselected_input_zip_members_never_enter_payload(self):
        entries = {**self.runtime, "README.md": b"other source release",
                   "vendor/example/dev-tool/dev.phar": b"dev fixture",
                   "../escape": b"unselected hostile member"}
        _, payload = PACKAGER.dependencies(self.root, self.app, self.source_zip(entries))
        self.assertEqual({**self.runtime, SUPPLEMENT: self.supplement}, payload)

    def test_archive_roundtrip_is_deterministic_and_preserves_modes(self):
        entries = {"src/line-endings.txt": b"one\r\ntwo\r\n", "scripts/run.sh": b"#!/bin/sh\n"}
        modes = {"scripts/run.sh": 0o100755}
        first, second = self.base / "first.zip", self.base / "second.zip"
        PACKAGER.write_archive(first, entries, modes, STAMP)
        PACKAGER.write_archive(second, dict(reversed(list(entries.items()))), modes, STAMP)
        self.assertEqual(first.read_bytes(), second.read_bytes())
        with zipfile.ZipFile(first) as archive:
            self.assertEqual(sorted(entries), archive.namelist())
            self.assertEqual(0o100755, archive.getinfo("scripts/run.sh").external_attr >> 16)
            for name, data in entries.items():
                self.assertEqual(data, archive.read(name))

    def test_write_archive_never_overwrites_existing_file(self):
        path = self.base / "existing.zip"
        path.write_bytes(b"reviewed artifact")
        with self.assertRaisesRegex(ValueError, "Refusing to replace"):
            PACKAGER.write_archive(path, {"README.md": b"new"}, {}, STAMP)
        self.assertEqual(b"reviewed artifact", path.read_bytes())

    def test_package_outputs_are_scoped_and_deterministic(self):
        self.write("vendor/example/dev-tool/dev.phar", b"ignored development tool")
        self.write("vendor/autoload.php", b"<?php // generated\n")
        self.write("vendor/composer/installed.php", b"<?php // generated\n")
        first, second = self.base / "one", self.base / "two"
        manifest = PACKAGER.package(self.root, first)
        self.assertEqual(manifest, PACKAGER.package(self.root, second))
        self.assertFalse(manifest["prerelease"])
        self.assertFalse(manifest["local_changes"])
        self.assertEqual(1, manifest["runtime_package_count"])
        for filename in ("shabbat-3.0.0.zip", "shabbat-3.0.0-corresponding-source.zip",
                         "RELEASE_MANIFEST.json", "SHA256SUMS"):
            self.assertEqual((first / filename).read_bytes(), (second / filename).read_bytes())
        with zipfile.ZipFile(first / "shabbat-3.0.0.zip") as archive:
            self.assertEqual(set(self.app) | {"build-info.json"}, set(archive.namelist()))
            for path, data in self.app.items():
                self.assertEqual(data, archive.read(path))
            metadata = json.loads(archive.read("build-info.json"))
            self.assertEqual(manifest["commit"], metadata["commit"])
            self.assertEqual(manifest["application_source_sha256"], metadata["application_source_sha256"])
        with zipfile.ZipFile(first / "shabbat-3.0.0-corresponding-source.zip") as archive:
            expected = set(self.app) | set(self.runtime) | {
                SUPPLEMENT, "build-info.json", "source-scope.json", "SOURCE-SCOPE.md",
            }
            self.assertEqual(expected, set(archive.namelist()))
            scope = json.loads(archive.read("source-scope.json"))
            self.assertEqual(manifest["commit"], scope["release_commit"])
            self.assertEqual("v3.0.0", scope["release_tag"])
            for path, value in scope["files_sha256"].items():
                self.assertEqual(value, digest(archive.read(path)))
        for line in (first / "SHA256SUMS").read_text().splitlines():
            expected, filename = line.split("  ", 1)
            self.assertEqual(expected, digest((first / filename).read_bytes()))

    def test_package_refuses_existing_output_without_partial_replacement(self):
        output = self.base / "output"
        output.mkdir()
        retained = output / "RELEASE_MANIFEST.json"
        retained.write_bytes(b"reviewed manifest")
        with self.assertRaisesRegex(ValueError, "Release outputs already exist"):
            PACKAGER.package(self.root, output)
        self.assertEqual([retained], list(output.iterdir()))
        self.assertEqual(b"reviewed manifest", retained.read_bytes())


if __name__ == "__main__":
    unittest.main(verbosity=2)
