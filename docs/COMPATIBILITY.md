# Compatibility Contract

This is the target/evidence matrix for the generic `1.0.0-x5` distribution. It distinguishes the owner's existing local testing from checks performed on the portability adaptations.

| Component | Target | Current evidence |
| --- | --- | --- |
| Discuz | DX X5 | X5.0.4 Intl 20261010 isolated native install/disable/uninstall evidence; source changes have scoped regression, not a claim of fresh installation for every candidate |
| PHP | 8.2+; bundled QR support requires 8.2 | Syntax/synthetic checks on 8.2.34 and local 8.5; no full application certification |
| PHP extensions | Sodium, mbstring, JSON, mysqli and HTTPS transport; process supervision for CLI workers | Full runtime matrix not run |
| Database | MySQL 8 with InnoDB | MySQL 8.4.11 native schema/lifecycle and synthetic-provider binding transactions passed; financial rules follow the locally tested implementation, with no new payout execution by this release task |
| MariaDB | Not yet claimed | Not run |
| HTTPS | Canonical root origin with strict host/origin checks | Generic configuration checked synthetically; subdirectory deployments not claimed |
| UI | PC and Touch | Existing native browser/display evidence and the approved original four-case member-controller retest passed; synthetic source, not real provider approval proof |
| Scheduling | Plugin-owned CLI; no frontend heavy work | Enabled public-example child/lock/status passed. Separate real SQL/default-HTTPS member-cache fill passed in-process; member scheduler-child/participation coverage is not claimed |
| Private display storage | Outside-webroot directory with owner-only access; same Web/CLI user | Isolated default storage and native browser hot-cache reads passed; unrelated host/shared-hosting policies are not certified |
| DX X3.x | Not supported by the initial contract | No compatibility claim |

Native interactions, task adapters and credit rewards retain the authoritative implementation's own permissions and settings. The generic package does not add release-only closures or silently overwrite official core files. It does not claim independent verification of every DX version.

Customized existing deployments are not an upgrade target for the first generic package. Do not install a generic candidate over an existing customized integration without a reviewed data and compatibility path.

Shared-hosting environments that cannot provide the required private cache or process supervision are not currently claimed as supported. Do not weaken isolation to simulate compatibility.
