# Architecture And Integration Boundaries

## Planned Components

- Account connection: forum-session-bound QR challenges, explicit Togya approval and encrypted credentials.
- Activities: native DX topic mappings, publisher groups and per-viewer authorization.
- Display: local fragments and authorized image caches; no synchronous source fallback on cold/error display paths.
- Background work: plugin-owned CLI with isolation, locks, time limits, backoff and sanitized counters.
- Optional adapters: explicit native integration for tasks, discussions, ratings and rewards.

## Generalization Requirements

Each installation needs an independent stable identity. A hostname change must not create new reward entitlements. The connected database must match forum configuration; arbitrary caller-supplied database/host values cannot authorize access.

Production configuration, customer allowlists, account fixtures and private maintenance frameworks are excluded. Distribution must not include complete official core files or require copying an entire modified forum tree.

## Security Invariants

1. Member operations check the active forum session, origin and form token.
2. Caches do not grant permissions; current forum/member authorization remains effective.
3. Uncertain source, transaction or authorization results fail closed, not silently retried as successful writes.
4. Credits retain transactions, bounded amounts and stable duplicate-entitlement guards.
5. Missing native integration disables the backend operation, not just its button.

These are release requirements, not proof that implementation or verification is complete.
