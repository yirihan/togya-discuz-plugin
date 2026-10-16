# Privacy And Data Handling

## Distribution

The public repository, commit metadata, examples and packages must not contain customer identifiers, deployment configuration, database exports, account records, credentials, logs, screenshots or source-repository history. Examples use reserved example domains and synthetic identities.

## Runtime

The planned integration uses forum user IDs, Togya account IDs, encrypted session credentials and activity/task state. Authorized content and images may be stored locally. Background synchronization is not a promise of immediate deletion or real-time propagation of every upstream change.

## Candidate Retention And Access

The current candidate's schema-2 display mirrors have no fixed time expiry. Queue expiration/collection does not erase retained content, image bytes or permission-denial markers. This is not a 31-day deletion policy. The candidate has a bounded private-cache disk budget; reaching it is not a promise of age-based eviction.

Known denied resources must not be delivered, even while bytes remain retained. Current local DX reading rights and binding identity/generation remain separate authorization gates. Upstream changes are observed by background synchronization, not promised to propagate instantaneously. Opening a thread must not trigger live source fallback.

Display snapshots and their private image store are outside the webroot and require the same filesystem owner as Web PHP/CLI with owner-only access. Public covers/native forum attachments have their own storage and access rules; this is not a claim that all forum attachments are private or encrypted. Only session credentials are described as encrypted here.

Unlinking/disabling access is not a general erasure operation. The candidate's uninstall preserves its tables and installation identity, and no blanket data-purge command is provided. Operators must define their retention/deletion process before collecting actual member content, including retained mirrors, forum attachments, account records and backups.

These statements are source-derived candidate behavior, not a completed lifecycle or access-control certification. The native install and end-to-end gates remain outstanding.

## Reports

Never post tokens, cookies, QR authorization payloads, private photos or raw production logs in issues. Use synthetic reproductions. This describes engineering requirements, not legal compliance or certification.
