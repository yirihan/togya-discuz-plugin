# Release Status

**Publication A: documentation preview. No supported installable release yet.**

| Stage | Status | Evidence / next gate |
| --- | --- | --- |
| P0 Baseline and contract | Complete | Fixed extraction inventory and dependency review kept private; public compatibility and architecture contracts present |
| P1 Public introduction | Published | Public visibility, README content and 0BSD license read back through GitHub; clean independent history |
| P2 Installation candidate | Basic lifecycle checks passed; coverage limitations retained | One explicitly authorized additional fresh install passed for alpha.1; alpha.2 upgrade and normal uninstall passed. Active-binding denial-message capture remains unverified; two configurations have synthetic, not dual native-install coverage |
| P3 Basic RC | Native HTML/access subset passed; RC incomplete | PC/Touch home/account HTTP and native guest-read permissions checked; activity hot-cache/rendering and actual provider approval remain outstanding |
| P4 Optional native extensions | Not started | Native task/interaction/credit gates; unverified features backend-disabled |
| P5 Stable release | Not started | Clean ZIP, Linux extraction, regression evidence and downloaded checksum |

## Verification Ledger

| Gate | Attempts | Result | Limitation |
| --- | --- | --- | --- |
| Private extraction inventory | 1 | Passed | Not installation proof |
| Runtime/container availability | Dedicated environment provisioned | Available | Isolated Linux VM, 2 CPUs/2 GiB; PHP 8.2.34 and MySQL 8.4.11, non-root PHP runtime; existing default context unchanged |
| Public documentation privacy | 1/3 | Passed for published snapshot | Documentation/metadata and local links checked; new documentation changes are checked before each upload |
| GitHub public/name/content readback | 1/3 | Passed | Repository PUBLIC, expected README read back; license recognized as 0BSD |
| Local candidate syntax | 1/3 | Passed | Linux PHP 8.2.34 linted 175 runtime PHP files; 10 JavaScript files checked; no runtime installation proof |
| Generic installation/unit boundaries | 1/3 | Passed | 33 synthetic checks; not a real database or DX install |
| Official core fixture | 1 | Prepared, not plugin-install proof | Official X5.0.4 Intl 20261010 schema/seed; 4,995 original core files compared without modification |
| Native plugin installation | Original 3 + one explicitly approved exception | Additional alpha.1 installation passed | Eleven InnoDB plugin tables, valid installation identity, disabled by default; not a fresh-install certification of later alpha.2 |
| Prerequisite repair diagnosis | Targeted diagnosis only | No error in native cache prerequisites | Official UC schema added; fixture now has 300 InnoDB tables; native plugin/setting/style cache diagnosis succeeds. This is not a fourth installation attempt |
| CLI bootstrap/negative arguments | 1/3 | Passed synthetically | Five regression cases, including native-style global cleanup; not a real scheduler execution proof |
| Packaging tooling | 1/3 | Passed | Eight synthetic tests; two private builds byte-identical; Linux extraction verified the full payload manifest and zero Apple sidecars; development archives predate later documentation changes |
| Binding/session components | 1/3 | Passed, synthetic only | 16 cases/42 assertions with real model classes, in-memory storage and fake API; no network, SQL, native login or install proof |
| Supervisor components | 1/3 | Passed, process isolation only | Six child-process cases, including timeout, output cap and malformed response; no DB-lock or cron proof |
| QR components | 1/3 | Passed, offline only | Six URL/MIME/render cases using the bundled SVG renderer; no real camera or Togya approval proof |
| Native enable/disable | 1 each | Passed on isolated fixture | Not a PC/Touch or authorization certification |
| Native upgrade | 2/3 | Passed after targeted repair | alpha.1 to alpha.2, stable installation identity; strict migration allowlist now includes the plugin's own membership-sync table |
| Active-binding uninstall | 3/3 | Not fully verified; no further automatic retry | Registration and binding survived, but the harness did not capture the expected denial message; nested native output buffers invalidate that part of the evidence |
| Retained-data uninstall | 2/3 | Passed after fixture repair | Native delete flow completed the uninstall hook, removed registration and retained the synthetic binding; corrected AdminCP context and restored test metadata only, without re-running installation. Not a browser-authentication test |
| Native maintenance | Initial failure, one repair/retest | Status and disabled run passed after repair | CLI must bind the global environment after DX bootstrap; wrong host rejected and unverified rewards skipped. No live-provider or enabled synchronization proof |
| Generic canonical hosts | 1/3 | 44 focused assertions passed on macOS/Linux | alpha.3 shares the strict canonical-host validator across three activity constructors; removes an unintended www-only restriction. No origin or identity relaxation |
| Native PC/Touch HTTP | Original 3 + one explicitly approved exception | Additional alpha.3 check passed 10/10 | Home/account for guest and synthetic member; CSRF/origin/guest-write/closed-extension checks; binding counts unchanged. Loopback TLS-termination model, not real TLS, visual or provider proof |
| Native guest-read permissions | 3/3 | Passed 6/6 | Public topic allowed; private forum, high readperm, hidden first post, deleted thread and password forum denied. Missing native usergroup cache fixed without changing permission values; synthetic SQL fixtures rolled back |

The invalid-CSRF negative test is accepted only for the plugin's explicit refusal or DX's exact native request-tainting refusal page. DX returns HTTP 503 for that native rejection before dispatch; it is not treated as a successful normal page or as proof that arbitrary 503 responses are safe.

The plugin program candidate is not uploaded. A development archive exists locally but is not published, certified or described as installable. No RC or stable release exists. Optional native tasks/interactions/rewards remain server-side disabled pending their own evidence.

The current publication remains documentation and packaging tools only. The owner explicitly approved one additional fixed-candidate installation attempt; it was consumed once and passed. This does not erase the original failures or authorize more installation retries. Unchanged-path installation evidence is retained; later source changes have their scoped evidence rather than invented fresh-install claims. Normal uninstall passed after repairing the isolated harness. Both example configurations passed synthetic isolation tests; only one has native lifecycle evidence. The separately approved single HTTP retest was also consumed once and passed. Activity cached-content rendering and provider verification remain incomplete. The active-binding uninstall gate reached its own three-attempt limit and is not automatically retried; registration/data retention was observed, but the missing prompt evidence is not relabeled a pass.

## Bounded Acceptance

Freeze candidate and checklist before testing. For the same failure: one initial run plus at most two targeted repair/retest rounds. Reuse valid unchanged-candidate evidence; do not continuously expand requirements.

If unresolved, record cause, impact, next action and downgrade. Options: documentation preview, basic RC, disabled optional modules or delayed marketplace submission. Privacy leaks, authorization bypass and unsafe/duplicate credit writes cannot be downgraded to a pass.

Built, packaged, installed, launched, tested, uploaded, released, submitted and approved are different states. A repository or static test does not prove end-to-end functionality.
