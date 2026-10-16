# Release Status

**Publication A: documentation preview. No supported installable release yet.**

| Stage | Status | Evidence / next gate |
| --- | --- | --- |
| P0 Baseline and contract | Complete | Fixed extraction inventory and dependency review kept private; public compatibility and architecture contracts present |
| P1 Public introduction | Preparing publication | English/Chinese introduction and roadmap; GitHub readback still required |
| P2 Installation candidate | Not started | Generalization and isolated fresh-install/disable/uninstall checks |
| P3 Basic RC | Not started | Account, authorization, PC/Touch, cache and worker checks |
| P4 Optional native extensions | Not started | Native task/interaction/credit gates; unverified features backend-disabled |
| P5 Stable release | Not started | Clean ZIP, Linux extraction, regression evidence and downloaded checksum |

## Verification Ledger

| Gate | Attempts | Result | Limitation |
| --- | --- | --- | --- |
| Private extraction inventory | 1 | Passed | Not installation proof |
| Runtime/container availability | 2 checks | Not available locally | Default engine not running; no alternate local context. Isolated DB tests not run |
| Public documentation privacy | 0/3 | Not run | Required before initial push |
| GitHub public/name/content readback | 0/3 | Not run | Repository not created yet |

## Bounded Acceptance

Freeze candidate and checklist before testing. For the same failure: one initial run plus at most two targeted repair/retest rounds. Reuse valid unchanged-candidate evidence; do not continuously expand requirements.

If unresolved, record cause, impact, next action and downgrade. Options: documentation preview, basic RC, disabled optional modules or delayed marketplace submission. Privacy leaks, authorization bypass and unsafe/duplicate credit writes cannot be downgraded to a pass.

Built, packaged, installed, launched, tested, uploaded, released, submitted and approved are different states. A repository or static test does not prove end-to-end functionality.
