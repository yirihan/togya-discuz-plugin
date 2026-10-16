# Togya Plugin

**Bring shared learning journeys into your Discuz X5 community.**

[简体中文](README.zh-CN.md) · [Release Status](docs/STATUS.md) · [Compatibility](docs/COMPATIBILITY.md) · [Privacy](docs/PRIVACY.md)

Togya Plugin is a developing integration between Discuz X5 forums and [Togya](https://togya.com). It is designed to connect forum accounts, turn supported Togya activities into forum topics, and show authorized progress inside the community.

**Current publication: documentation preview. There is no supported installable release yet. Do not install an arbitrary repository snapshot on a production forum.**

## Planned Capabilities

| Capability | Intended behavior | Standalone release status |
| --- | --- | --- |
| Account linking | Scan a QR code in Togya and explicitly approve an account-scoped connection; unlink safely | First RC target; not yet verified |
| Activity topics | Authorized publishers connect a supported activity to a native forum topic | First RC target |
| Progress display | Authorized stages and check-ins through PC and Touch views | First RC target |
| Background synchronization | Thread display reads local caches, without synchronous Togya calls | Required; standalone scheduling in development |
| Native tasks and interactions | Optional task checks and native discussion/rating integration | Separate integration gate; not promised as drop-in |
| Credit rewards | Administrator-configured rewards, transactions and duplicate-payment protection | Disabled until dedicated financial tests pass |

These are capabilities being prepared, not claims of completed installation or end-to-end tests. See the [evidence-backed status](docs/STATUS.md).

## A Forum Extension, Not A Core Replacement

- The internal identifier remains `pintogya` for route and protocol compatibility. The display name is **Togya Plugin**.
- The planned package lives under `source/plugin/pintogya/`; it does not distribute a complete Discuz installation.
- It must not depend on private maintenance frameworks, fixed customer domains or database names.
- The forum's own reading, posting, moderation and credit permissions remain authoritative.
- Features needing extra core integration must be identified explicitly and rejected server-side when integration is missing.

## Requirements And Setup

The target is **Discuz X5, PHP 8.2+, HTTPS and transactional InnoDB tables**. This is a requirements statement, not certification of every version combination. X3.x compatibility has not been established.

The first RC documentation will cover:

1. Verify the exact supported DX/PHP/database combination and back up the forum.
2. Upload the release package and install through Discuz AdminCP.
3. Configure the canonical HTTPS origin, forum, publisher groups and credit fields using native controls.
4. Run plugin-owned background synchronization as the web-service account.
5. Refresh all Discuz caches through AdminCP; verify PC and Touch behavior.

No credentials, account data or customer configuration will be included. Operators need their own configuration and permitted access to Togya's service. Synthetic fixtures do not prove a real authorization flow succeeded.

## Staged Delivery

1. **Introduction preview:** architecture, privacy boundaries and release plan.
2. **Installation candidate:** general configuration plus isolated fresh-install, disable and uninstall checks.
3. **RC:** downloadable package, fixed source manifest, checksums and known limitations.
4. **Optional extensions:** separately qualify tasks, interactions and rewards; unverified modules stay disabled server-side.
5. **Stable:** tested ZIP, upgrade/recovery guidance and downloaded checksum verification.

Marketplace submission is separate from a GitHub release. A draft or submission is not an approved listing.

## Security And Data

Read [Privacy and Data Handling](docs/PRIVACY.md) before deployment. The design includes encrypted session credentials and locally retained authorized activity content, which can include photos; it is not a no-data-storage integration. Never post tokens, cookies, account records or raw production logs in issues. See [SECURITY.md](SECURITY.md).

## License

Original project code is licensed under [0BSD](LICENSE). You may use, modify and redistribute it, including commercially, without an attribution or notice-retention condition under that license. No warranty is provided.

Third-party components keep their own licenses and notices; 0BSD does not relicense them or the Discuz core. Their distribution inventory will accompany the first code package.

## Contributing

Use synthetic data and reserved example domains in reports. Do not upload customer installations, backups or modified official core files. Read [CONTRIBUTING.md](CONTRIBUTING.md).
