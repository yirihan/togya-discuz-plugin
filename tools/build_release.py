#!/usr/bin/env python3
"""Build a deterministic source package, not an installation certification."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import sys
import xml.etree.ElementTree as ET
import zipfile


SECRET = re.compile(
    rb"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|"
    rb"gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}|AKIA[0-9A-Z]{16}"
)
BAD_NAMES = {"config_global.php", "config_ucenter.php", "id_rsa", "id_ed25519"}
TASK_KEYS = ("bind_daily", "youtube_connected", "youtube_import_public",
             "youtube_import_three_participants", "answer_other_question",
             "created_activity_three_completions")
OWNED_RUNTIME_FILES = tuple("source/class/task/task_togya_" + key + ".php" for key in TASK_KEYS) + (
    "static/image/task/togya.png",)


def validate_bytes(name, data, deny):
    if any(value.lower() in (name.encode() + b"\n" + data).lower() for value in deny):
        raise ValueError("private_identifier_detected")
    if SECRET.search(data) or re.search(rb"/Users/[A-Za-z0-9_.-]+/|/home/runner/_private/", data):
        raise ValueError("credential_or_private_path_detected")


def build(source, output, deny):
    if source.is_symlink() or not source.is_dir():
        raise ValueError("invalid_source_directory")
    source = source.resolve()
    document = ET.parse(source / "discuz_plugin_pintogya.xml").getroot()
    metadata = document.find("./item[@id='Data']/item[@id='plugin']")
    if metadata is None:
        raise ValueError("missing_plugin_metadata")
    fields = {item.attrib["id"]: item.text or "" for item in metadata.findall("item")}
    version = fields.get("version", "")
    if fields.get("identifier") != "pintogya" or fields.get("name") != "Togya Plugin":
        raise ValueError("unexpected_plugin_identity")
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+(?:-(?:alpha|rc)\.[0-9]+)?-x5", version):
        raise ValueError("invalid_release_version")
    if output.name != "Togya-Plugin-" + version + ".zip":
        raise ValueError("artifact_name_mismatch")
    section = document.find("./item[@id='Data']")
    for key in ("installfile", "upgradefile", "uninstallfile", "checkfile"):
        entry = section.find("./item[@id='" + key + "']")
        name = entry.text if entry is not None else ""
        if not name or not re.fullmatch(r"[A-Za-z0-9_]+\.php", name) or not (source / name).is_file():
            raise ValueError("missing_lifecycle_entry")
    declared = re.search(r"const VERSION = '([^']+)';", (source / "lib/installation.php").read_text())
    if not declared or declared[1] != version:
        raise ValueError("version_mismatch")
    migrations = re.findall(r"'([0-9]{4}_[A-Za-z0-9_]+\.php)'\s*=>\s*'([0-9a-f]{64})'", (source / "lib/pintogya.php").read_text())
    if not migrations:
        raise ValueError("missing_migration_inventory")
    for name, expected in migrations:
        if hashlib.sha256((source / "migrations" / name).read_bytes()).hexdigest() != expected:
            raise ValueError("migration_integrity_failed")
    payload = {}
    skipped = 0
    for path in sorted(source.rglob("*")):
        relative = path.relative_to(source)
        if path.is_symlink():
            raise ValueError("symlink_in_source")
        if any(part.startswith("._") or part in {"__MACOSX", ".DS_Store"} for part in relative.parts):
            skipped += int(path.is_file())
            continue
        if not path.is_file():
            continue
        if relative.parts[0] in {"data", "config", ".git", "private"} or path.name in BAD_NAMES:
            raise ValueError("deployment_data_in_source")
        if path.name.startswith(".env") or path.name.endswith((".log", ".sql", ".sqlite", ".bak", ".tpl.php")):
            raise ValueError("runtime_or_backup_file_in_source")
        name = "upload/source/plugin/pintogya/" + relative.as_posix()
        if not re.fullmatch(r"[A-Za-z0-9_./-]+", name):
            raise ValueError("unsupported_archive_path")
        data = path.read_bytes()
        validate_bytes(name, data, deny)
        payload[name] = data
    # Include only our named task adapters/icon, never official core or arbitrary upload files.
    if source.parent.name == "plugin" and source.parent.parent.name == "source":
        runtime = source.parent.parent.parent
        for relative in OWNED_RUNTIME_FILES:
            path = runtime / relative
            if path.is_symlink() or any(parent.is_symlink() for parent in path.parents if parent != runtime.parent):
                raise ValueError("symlink_in_runtime_payload")
            if not path.is_file():
                raise ValueError("missing_native_task_payload")
            name = "upload/" + relative
            data = path.read_bytes()
            validate_bytes(name, data, deny)
            payload[name] = data
    repository = Path(__file__).resolve().parent.parent
    documents = [Path(name) for name in ("README.md", "README.zh-CN.md", "LICENSE", "SECURITY.md", "CONTRIBUTING.md")]
    documents.extend(path.relative_to(repository) for path in sorted((repository / "docs").glob("*.md")))
    documents.extend(Path(name) for name in ("docs/images/activity-pc.png", "docs/images/activity-touch.png"))
    for relative in documents:
        name = relative.as_posix()
        path = repository / name
        if path.is_symlink() or not path.is_file():
            raise ValueError("invalid_document_source")
        data = path.read_bytes()
        validate_bytes(name, data, deny)
        payload[name] = data
    payload["PACKAGING.json"] = (json.dumps({
        "product": "Togya Plugin", "identifier": "pintogya", "version": version,
        "scope": "source archive; packager does not certify runtime or installation gates",
        "apple_metadata_omitted": skipped,
    }, sort_keys=True, indent=2) + "\n").encode()
    manifest = "".join(hashlib.sha256(data).hexdigest() + "  " + name + "\n"
                       for name, data in sorted(payload.items()))
    payload["MANIFEST.sha256"] = manifest.encode()
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, data in sorted(payload.items()):
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    output.with_suffix(output.suffix + ".sha256").write_text(digest + "  " + output.name + "\n")
    return {"artifact": output.name, "version": version, "files": len(payload), "sha256": digest,
            "runtime_certified": False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--deny-stdin", action="store_true", help="Read private identifier strings as a JSON array from stdin; never store or print them")
    args = parser.parse_args()
    deny = json.load(sys.stdin) if args.deny_stdin else []
    if not isinstance(deny, list) or any(not isinstance(item, str) or not item for item in deny):
        raise ValueError("invalid_private_identifier_list")
    result = build(args.source, args.output, [value.encode() for value in deny])
    print(json.dumps(result))


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, ET.ParseError) as error:
        # Never echo raw payloads, private identifiers, credentials or source paths.
        print(json.dumps({"passed": False, "code": str(error) if isinstance(error, ValueError) else "packaging_input_failed"}))
        sys.exit(1)
