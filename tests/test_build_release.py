import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
import zipfile


SPEC = importlib.util.spec_from_file_location("build_release", Path(__file__).resolve().parents[1] / "tools/build_release.py")
builder = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(builder)


class ReleasePackagingTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="togya-package-")
        self.root = Path(self.temporary.name)
        self.source = self.root / "pintogya"
        (self.source / "lib").mkdir(parents=True)
        (self.source / "migrations").mkdir()
        migration = b"<?php // synthetic fixture only\n"
        (self.source / "migrations/0001_initial.php").write_bytes(migration)
        digest = hashlib.sha256(migration).hexdigest()
        (self.source / "lib/pintogya.php").write_text("<?php\nreturn ['0001_initial.php' => '" + digest + "'];\n")
        (self.source / "lib/installation.php").write_text("<?php\nclass Fixture { const VERSION = '1.0.0-alpha.1-x5'; }\n")
        for name in ("install", "upgrade", "uninstall", "check"):
            (self.source / (name + ".php")).write_text("<?php // synthetic lifecycle\n")
        lifecycle = "".join('<item id="' + name + 'file">' + name + '.php</item>'
                            for name in ("install", "upgrade", "uninstall", "check"))
        (self.source / "discuz_plugin_pintogya.xml").write_text(
            '<root><item id="Data"><item id="plugin"><item id="name">Togya Plugin</item>'
            '<item id="identifier">pintogya</item><item id="version">1.0.0-alpha.1-x5</item>'
            '</item>' + lifecycle + '</item></root>')
        self.output = self.root / "one/Togya-Plugin-1.0.0-alpha.1-x5.zip"

    def tearDown(self):
        self.temporary.cleanup()

    def test_deterministic_complete_package(self):
        first = builder.build(self.source, self.output, [])
        second = builder.build(self.source, self.root / "two" / self.output.name, [])
        self.assertEqual(first["sha256"], second["sha256"])
        self.assertFalse(first["runtime_certified"])
        with zipfile.ZipFile(self.output) as archive:
            self.assertIn("docs/STATUS.md", archive.namelist())
            self.assertIn("CONTRIBUTING.md", archive.namelist())
            for entry in archive.infolist():
                self.assertEqual(entry.external_attr >> 16, 0o100644)
                self.assertEqual(entry.date_time, (2026, 1, 1, 0, 0, 0))
            for line in archive.read("MANIFEST.sha256").decode().splitlines():
                digest, name = line.split("  ", 1)
                self.assertEqual(hashlib.sha256(archive.read(name)).hexdigest(), digest)

    def test_private_identifier_not_echoed(self):
        (self.source / "private-note.txt").write_text("customer.example")
        with self.assertRaisesRegex(ValueError, "^private_identifier_detected$"):
            builder.build(self.source, self.output, [b"customer.example"])
        self.assertFalse(self.output.exists())

    def test_credential_pattern_rejected(self):
        (self.source / "note.txt").write_text("g" + "hp_" + "x" * 36)
        with self.assertRaisesRegex(ValueError, "^credential_or_private_path_detected$"):
            builder.build(self.source, self.output, [])

    def test_symlink_rejected(self):
        outside = self.root / "outside.txt"
        outside.write_text("fixture")
        (self.source / "linked.txt").symlink_to(outside)
        with self.assertRaisesRegex(ValueError, "^symlink_in_source$"):
            builder.build(self.source, self.output, [])

    def test_root_symlink_rejected(self):
        linked = self.root / "linked"
        linked.symlink_to(self.source, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, "^invalid_source_directory$"):
            builder.build(linked, self.output, [])

    def test_runtime_configuration_rejected(self):
        (self.source / ".env").write_text("fixture only")
        with self.assertRaisesRegex(ValueError, "^runtime_or_backup_file_in_source$"):
            builder.build(self.source, self.output, [])

    def test_lifecycle_and_migration_integrity(self):
        (self.source / "check.php").unlink()
        with self.assertRaisesRegex(ValueError, "^missing_lifecycle_entry$"):
            builder.build(self.source, self.output, [])
        (self.source / "check.php").write_text("<?php\n")
        (self.source / "migrations/0001_initial.php").write_text("changed")
        with self.assertRaisesRegex(ValueError, "^migration_integrity_failed$"):
            builder.build(self.source, self.output, [])

    def test_apple_metadata_excluded(self):
        (self.source / "._metadata").write_text("fixture")
        result = builder.build(self.source, self.output, [])
        with zipfile.ZipFile(self.output) as archive:
            self.assertFalse(any("._" in name for name in archive.namelist()))
            self.assertIn('"apple_metadata_omitted": 1', archive.read("PACKAGING.json").decode())
        self.assertTrue(result["sha256"])


if __name__ == "__main__":
    unittest.main()
