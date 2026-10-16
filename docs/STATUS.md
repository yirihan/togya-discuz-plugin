# Release Status

**Publication A: documentation preview. No supported installable release yet.**

| Stage | Status | Evidence / next gate |
| --- | --- | --- |
| P0 Baseline and contract | Complete | Fixed extraction inventory and dependency review kept private; public compatibility and architecture contracts present |
| P1 Public introduction | Published | Public visibility, README content and 0BSD license read back through GitHub; clean independent history |
| P2 Installation candidate | In progress, private staging | Generic identity/DB scope and standalone worker implemented in local staging; real X5 install/disable/uninstall tests remain not run |
| P3 Basic RC | Not started | Account, authorization, PC/Touch, cache and worker checks |
| P4 Optional native extensions | Not started | Native task/interaction/credit gates; unverified features backend-disabled |
| P5 Stable release | Not started | Clean ZIP, Linux extraction, regression evidence and downloaded checksum |

## Verification Ledger

| Gate | Attempts | Result | Limitation |
| --- | --- | --- | --- |
| Private extraction inventory | 1 | Passed | Not installation proof |
| Runtime/container availability | 2 checks | Not available locally | Default engine not running; no alternate local context. Isolated DB tests not run |
| Public documentation privacy | 1/3 | Passed | Eleven documentation/metadata files; no customer identifiers or secrets, local links resolve |
| GitHub public/name/content readback | 1/3 | Passed | Repository PUBLIC, expected README read back; license recognized as 0BSD |
| Local candidate syntax | 1/3 | Passed | 174 PHP files including the synthetic test harness, plus 10 JavaScript files; no runtime installation proof |
| Generic installation/unit boundaries | 1/3 | Passed | 33 synthetic checks; not a real database or DX install |

The program candidate is not uploaded and no ZIP, RC or stable release has been published. Optional native tasks/interactions/rewards remain server-side disabled in the candidate pending their own integration evidence.

## Bounded Acceptance

Freeze candidate and checklist before testing. For the same failure: one initial run plus at most two targeted repair/retest rounds. Reuse valid unchanged-candidate evidence; do not continuously expand requirements.

If unresolved, record cause, impact, next action and downgrade. Options: documentation preview, basic RC, disabled optional modules or delayed marketplace submission. Privacy leaks, authorization bypass and unsafe/duplicate credit writes cannot be downgraded to a pass.

Built, packaged, installed, launched, tested, uploaded, released, submitted and approved are different states. A repository or static test does not prove end-to-end functionality.
