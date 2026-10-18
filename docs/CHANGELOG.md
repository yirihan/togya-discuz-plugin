# Changelog

## 1.0.0-alpha.8-x5 (Unpublished Working Source)

- Align with the authoritative local feature/rule set: remove added capability closures for native tasks, interactions and rewards, and the added zero-only publication restriction. Native permission/configuration, transaction and idempotency checks remain.
- Twelve restored rule-method bodies match authority PHP tokens exactly (comments/whitespace ignored); 47 portable checks and 14 changed/new PHP syntax checks pass. This is scoped parity evidence, not full-plugin equivalence or payout execution proof.
- Remaining native-task packaging, guide-asset anonymization and other portability differences are under review. No Alpha.8 archive is published. Historical Alpha evidence/disabled-feature behavior is retained as history, not the final feature contract.

## 1.0.0-alpha.7-x5 (Experimental Source Candidate)

- Security: member images are no longer materialized in public attachment storage or advertised with static URLs. Reads use the existing authenticated, CSRF-protected native endpoint and same-origin POST; public images retain their normal static behavior.
- Browser images use validated image blobs, two concurrent requests, stale-response cancellation and URL revocation. Member, binding and native reading permissions remain checked on every protected HTTP read.
- Maintenance retires only identified legacy member mirrors whose retained private bytes and public hash match, records evidence before removal and preserves the private copy. Lost job identity cannot be recovered safely by this migration; review unknown legacy files before reuse.
- Five native private-media HTTP cases and six disk-boundary cases/16 assertions pass with synthetic data. Browser transport/decoding checks use synthetic responses, not real provider approval.
- The separately approved single Alpha.5 controller HTTP retest was consumed once and passed all four original cases: PC/Touch publication, duplicate refusal without another topic, guest refusal. No additional installation or retry is implied.

Alpha.6 and earlier remain immutable, unsafe for real private member-image content. Alpha.7 source is not itself a published download or an RC/stable claim.

## 1.0.0-alpha.6-x5 (Experimental Candidate)

- Create the immediate forum-attachment parent when a fresh DX installation has not created it yet. Do not alter existing directory permissions. Eight macOS/Linux storage checks cover creation, unchanged parent modes, idempotency, invalid bytes and symlink-root refusal.
- Hide only the exact machine-owned activity marker in the native first-post render buffer, including native HTML line-break conversion. Do not change stored posts, edited content, other topics or replies. Portable boundary checks total 53.
- Native SQL/default-HTTPS member-cache integration passes six scenarios/18 assertions with a synthetic provider; cold/warm foreground reads make no upstream requests, and member/generation/revocation boundaries remain enforced.
- Synthetic native PC/Touch browser display, local PNG decoding, PC zoom trigger and denial of warm cached content pass. These are not member-posting, real app approval or public TLS claims. Add privacy-reviewed synthetic UI previews.

At Alpha.6 publication the bounded Alpha.5 full-member-controller gate was partial; the later separately authorized single retest is recorded above without erasing its failures. Alpha.5 was a source checkpoint only. Actual downloadable assets are listed on [GitHub Releases](https://github.com/yirihan/togya-discuz-plugin/releases); these notes alone are not publication proof.

## 1.0.0-alpha.5-x5 (Source Checkpoint Only)

- Add a stock DX before-model adapter for ordinary-member activity publication without replacing official core files. Bind canonical body, draft state, owner/session and completion to the same native model instance; ignore untrusted editor/funding/attachment fields.
- Preserve native moderation instead of automatically approving moderated topics. Duplicate completion does not repeat publication.
- Reject nonzero or legacy publication reward rules while credit-grant support remains disabled; zero-reward activity publication is the only candidate path.
- Eight native model/repository scenarios and 17 assertions pass with synthetic session/source and savepoint-adapted commits. This is not full controller HTTP, browser, real QR approval, native commit or payout proof. No Alpha.5 download has been published.

## 1.0.0-alpha.4-x5

- Fix the activity-message trailer to contain exactly the stock DX plugin identifier `pintogya`; keep the reservation digest outside that trailer. No official core replacement.
- Add bounded migration 0013 for byte-exact old markers on mapped first posts with matching forum/author/topic ownership. Edited bodies are not overwritten; conversion is idempotent and transactional.
- Close the unverified ordinary-member composer UI, submit/completion hooks, composer API and resume route. Stock DX ignores the submit callback's return value; a proper native before-model adapter remains to be implemented. This is an explicitly deferred path, not an implemented feature.
- Native zero-reward admin example publication, idempotency, failure rollback and unmodified stock-view dispatch checked with synthetic input. Transaction commits are savepoint-adapted in that fixture, so it is not native commit or payout proof.
- Enabled public-example synchronization checked with actual child processes/default private cache; member credentials and QR approval were not exercised.

Still an experimental Alpha, not RC/stable. Read [UPGRADING.md](UPGRADING.md) before upgrading an Alpha.3 test instance.

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
