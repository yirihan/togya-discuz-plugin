# Togya Plugin

Bring Togya account linking, native tasks and shared learning activities into Discuz X5.

[简体中文](README.zh-CN.md) · [Download](https://github.com/yirihan/togya-discuz-plugin/releases/tag/v1.0.0-x5) · [Installation](docs/INSTALL.md) · [Source Fidelity](docs/SOURCE_FIDELITY.md)

## Features

- Link/unlink forum accounts through Togya QR approval.
- Six native task adapters with original periods, conditions and tutorial images.
- Native activity topics, PC/Touch stages, progress, participants and check-ins.
- Native comments/ratings and configured rewards retain their existing permission, transaction and duplicate-entitlement rules.
- Local display cache and bounded background synchronization; opening a topic does not synchronously fetch its activity content from Togya.
- Generic forum labels, database-bound installation identity and a plugin-owned maintenance entry point.

**Version: `1.0.0-x5`.** This is an anonymized, portable distribution of the authoritative local implementation, not a redesigned or reduced feature set. It retains the local image behavior: feeds render ordinary DX static attachment URLs. Earlier experimental Alpha feature closures and private-image changes are not this version's behavior baseline.

## Install

Read [English installation](docs/INSTALL.md) or [中文安装说明](docs/INSTALL.zh-CN.md). Upload the complete `upload/` tree, install through native AdminCP, configure the forum/usergroup/credit settings and secret management, refresh native caches, and configure maintenance under the Web PHP user.

The package adds the plugin, six specifically named custom task classes and one task icon. It does not include complete official core files, customer configurations, credentials or business data. Use native task settings; uploading adapters does not create funded tasks or issue credits.

Target: **Discuz X5, PHP 8.2+, HTTPS and InnoDB**. See [compatibility](docs/COMPATIBILITY.md), [status](docs/STATUS.md), [upgrade notes](docs/UPGRADING.md), [privacy](docs/PRIVACY.md) and [synthetic previews](docs/PREVIEW.md). X3.x, MariaDB, arbitrary subdirectory/shared-hosting environments and every DX version are not independently certified.

## License

Original code uses [0BSD](LICENSE): use, modify and redistribute, including commercially, without an attribution requirement. No warranty is provided. Third-party libraries, trademarks and platform badges retain their respective notices. Included Togya Logo and example illustrations are authorized for redistribution; see the package's `THIRD_PARTY.md`.

Report issues with synthetic examples, version and sanitized errors. Never attach credentials, cookies or private production data.
