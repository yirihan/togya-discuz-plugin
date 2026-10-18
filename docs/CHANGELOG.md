# Changelog

## 1.0.0-alpha.5-x5 (Unreleased)

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
