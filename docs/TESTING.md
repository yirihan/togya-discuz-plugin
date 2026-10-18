# Testing The Development Candidate

Use disposable synthetic data only. Do not point component tests at an existing forum, use actual tokens or publish raw output containing credentials.

## Portable Checks

From the repository root, set the source path for the PHP tests:

```sh
export TOGYA_PLUGIN_SOURCE="$PWD/upload/source/plugin/pintogya"
php tests/installation.php
php tests/cli-bootstrap.php
php tests/host_components.php
php tests/cache_components.php
php tests/attachment_components.php
php tests/protocol_components.php
php tests/qr_components.php
php tests/supervisor_components.php
python3 -m unittest discover -s tests -p test_build_release.py -v
```

PHP requires the same extensions as the candidate; the supervisor/CLI checks create bounded local child processes and temporary synthetic files. The installation test uses a fake database and is **not** a native installer. Cache tests use real temporary files but synthetic clock/binding/native-cache callbacks. Protocol tests use a fake provider. No portable test proves actual app-camera authorization or a production deployment.

## Native Integration Evidence

An isolated complete X5.0.4 Intl 20261010 fixture with PHP 8.2.34/MySQL 8.4.11 was used for the scoped lifecycle, HTTP, guest permissions, SQL binding and cached-renderer checks recorded in [STATUS.md](STATUS.md). Official core files were not changed. The fixture/configuration, accounts, cookies, generated keys, logs and private QA workspace are not distributed.

Reproducing those checks requires a fresh disposable official DX installation and synthetic identities, not a partial forum SQL import or a copied live database. Use native AdminCP lifecycle/cache behavior, the same Web/CLI identity, and an outside-webroot private cache. Keep native/financial modules closed; do not infer financial or concurrency acceptance from these tests.

## Bounded Failures

Freeze source/checklist before running. Initial execution plus two targeted repair/retest rounds is the limit for the same gate, unless the owner explicitly approves a single exception. Preserve failure evidence. Do not relabel a failed gate as passed, reset counters or replace full-system requirements with a component test.
