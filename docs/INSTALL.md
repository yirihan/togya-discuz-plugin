# Operator Guide

**Guide for the published `1.0.0-x5` generic distribution. It preserves the owner's locally tested behavior; portable packaging checks are not a new full lifecycle/payment certification. Read the release status and compatibility boundaries before installation.**

[简体中文](INSTALL.zh-CN.md) · [Actual status](STATUS.md) · [Compatibility](COMPATIBILITY.md)

## Before Installation

Use a disposable, complete official DX X5 installation first. The plugin does not include the DX core. An isolated fixture must contain the official installation's required account/kernel schema, not only a partial forum-schema import. A separate UCenter server is not a plugin requirement; removing tables required by DX's own standalone mode is not supported.

The candidate targets PHP 8.2+, Sodium, mbstring, JSON, mysqli and working HTTPS transport. CLI supervision also needs process creation/termination. Use InnoDB and a web-service account that can write the forum's normal runtime/cache directories. Do not make source or language directories web-writable, run the worker as root, or weaken permissions to work around an error.

Back up an existing installation before any approved installation or upgrade. The initial public lineage is for fresh installs; it must not silently adopt a populated customized deployment. No database export, encryption key, account binding or production configuration is supplied.

## Candidate Installation Sequence

Once a release has actually passed its gates:

1. Download the named release asset and verify its external SHA-256 checksum and internal manifest. A repository commit or self-built ZIP is not a supported release.
2. Review its exact DX/PHP/database matrix and known limitations. Do not infer X3.x or subdirectory support.
3. Upload the contents of `upload/` into the DX root. The working package includes the plugin subtree, six named `source/class/task/task_togya_*.php` adapters and `static/image/task/togya.png`; no official core/config replacement is part of this package. Review existing same-named customized task files before replacing them.
4. Install `pintogya` through the native AdminCP plugin flow. Verify the displayed name is **Togya Plugin**, the release version matches, and enablement remains off until setup is reviewed.
5. Review DX's native basic-setting **site URL (`siteurl`)**. The candidate treats it as a trusted canonical forum origin: lower-case hostname, HTTPS, root path, no custom port, credentials, query or fragment. It is not inferred from an arbitrary incoming Host header. A different website/homepage URL is not a valid substitute for the forum origin.
6. Review the plugin's displayed connection-request name. It identifies the request to a member; changing it does not transfer account ownership.
7. Configure the encryption key and background worker. Features follow their existing native permissions and operator settings; the working source does not add release-only task/interaction/reward closures. Do not deploy an unverified working candidate to production or enable payments merely as a scheduler test.

### Existing Native Task Adapters

The six task types are `togya_bind_daily`, `togya_youtube_connected`, `togya_youtube_import_public`, `togya_youtube_import_three_participants`, `togya_answer_other_question` and `togya_created_activity_three_completions`. Use the native AdminCP task center to configure tasks and their existing group/period/reward rules. Uploading files does not create funded tasks or grant credits. The adapters retain their original task conditions, including administrator-only restrictions where present; do not remove those conditions to make a test pass. Original tutorial images are included with their existing paths and rendering.
8. Refresh all Discuz cache types through AdminCP, and check PC/Touch, member/guest and denied-access behavior in the isolated environment before deployment.

## Encryption Key

Use a deployment secret manager to generate a random 32-byte key and provide its unpadded base64url representation through `PINTOGYA_TOKEN_ENCRYPTION_KEY` to the PHP web runtime and CLI consistently. Do not use the synthetic test key. Do not paste keys into plugin settings, source, Git, screenshots, issue reports or command output.

The CLI has an optional private-file path via `PINTOGYA_TOKEN_ENCRYPTION_KEY_FILE`; its implementation enforces file ownership, mode and link checks. Follow the deployment's secret-management policy rather than removing those checks. The installer does not change existing filesystem permissions. Preserve the key securely with disaster-recovery material: changing it without a migration makes old encrypted credentials unreadable.

## Background Maintenance

### Private Display Storage

The candidate uses a private display-cache directory outside the forum webroot, derived from the real installation path under `/var/cache/`. It must be provisioned under the deployment's policy for the **same effective user** used by Web PHP and CLI, with owner-only access. The plugin does not relax an existing directory's permissions. PHP filesystem restrictions must permit this location; shared hosting without suitable private storage is not currently claimed as supported. Do not move private snapshots into a public web directory or remove the ownership checks as a workaround.

The expected entry point is plugin-owned and CLI-only. Run it as the forum's web-service account, using the verified PHP binary and the canonical host configured in DX:

```sh
php source/plugin/pintogya/maintenance.php status --host=forum.example.org
php source/plugin/pintogya/maintenance.php run --host=forum.example.org --limit=5
```

These are example commands, not proof of a successfully installed scheduler. `--host` does not override the configured origin. A system scheduler may call `run`; frontend visits must not run bulk synchronization. The candidate bounds supervised children and returns counters/closed errors, not provider bodies. Keep only sanitized operational output. Do not turn an uncertain result into an immediate retry loop or enable credit settlement to test scheduling.

## Upgrade, Disable And Uninstall

- Verify the current/target lineage and migration notes before upgrading. Do not install this generic lineage over an existing customized integration without a reviewed migration path.
- Use the native disable operation to stop normal use. Stop the installation's scheduled worker too; disabling is not a promise that historical data was deleted.
- The working source preserves the original rule: records in any of the twelve checked business tables refuse uninstall, including historical/revoked records, not only active sessions. Unlinking alone may therefore be insufficient. Use native disable to stop use; do not clear business records merely to bypass the guard. A pre-delete check applies the same condition before DX removes registration.
- Only after all those checked tables are empty does uninstall remove the original twelve-table list. It does not erase nonempty business data or perform remote revocation. The installation identity and tables outside that original list are not silently removed. This working policy has synthetic checks, not a fresh native-uninstall pass.
- Do not delete/regenerate the installation UUID to repair a hostname or database error. Reward keys use the stable installation namespace; resetting it can change entitlement identity. Copying a database to a new database name/prefix needs a deliberate migration, not automatic adoption.

## Troubleshooting

| Signal | Review | Do not do |
| --- | --- | --- |
| `feature_disabled` / `plugin_disabled` | Native availability and the base plugin setting | Enable every optional operation |
| `site_origin_not_configured` / `site_origin_invalid` | Native canonical `siteurl`, HTTPS/root constraints | Trust a caller-supplied Host as a fallback |
| Installation-identity/database-scope error | Successful lifecycle hook, configured database and prefix | Clear identity or reward tables to force adoption |
| Token-key error | Same secret supplied to web/CLI; private-file policy | Print the key or disable key-file checks |
| Private-cache directory error | Outside-webroot provisioning, effective owner and PHP filesystem policy | Make it public/web-writable or fall back to live source reads |
| Historical Alpha `extension_not_verified` | Version-specific restrictions and the current source-fidelity notes | Treat an older closed-feature test as proof of the full working feature set |
| `outcome_unknown` | Sanitized state and authoritative records | Blindly replay writes |

For repeated gate failures, retain evidence and follow the bounded acceptance in [STATUS.md](STATUS.md). A successful component test, packager or cache diagnosis does not reset an exhausted native-install gate.
