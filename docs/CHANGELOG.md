# Changelog

## 1.0.0-alpha.3-x5

Experimental development preview, not an RC or production-supported release.

- Standalone installation identity, canonical origin and database/prefix binding; no fixed customer host or database configuration.
- Plugin-owned maintenance CLI with bounded children, lock/lease checks and sanitized results; no private scheduler dependency.
- Default disabled; unverified native task, interaction and credit-grant backends remain closed.
- Preserve data on uninstall and use the native pre-delete check.
- Fix DX bootstrap clearing the CLI's global environment reference.
- Fix migration replay rejecting the plugin's own membership-sync table.
- Share strict canonical-host validation across activity services instead of requiring a `www.` prefix.
- Include original-code 0BSD terms, preserved third-party notices and owner-authorized product artwork; exclude customer screenshots, configuration, data and original repository history.

See [STATUS.md](STATUS.md) for exact evidence and known gaps. A synthetic-provider transaction test, an anonymous public GET and a rendered fragment are not real camera approval, enabled live synchronization or complete browser acceptance.
