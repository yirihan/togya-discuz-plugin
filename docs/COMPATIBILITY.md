# Compatibility Contract

This is a target matrix. No installable standalone release has passed it yet.

| Component | Target | Current evidence |
| --- | --- | --- |
| Discuz | DX X5 | Standalone install not run |
| PHP | 8.2+; bundled QR support requires 8.2 | Local PHP 8.5 tooling available; not an application test |
| PHP extensions | Sodium, mbstring, JSON, mysqli and HTTPS transport; process supervision for CLI workers | Full runtime matrix not run |
| Database | MySQL 8 with InnoDB | Isolated install and concurrency tests not run |
| MariaDB | Not yet claimed | Not run |
| HTTPS | Canonical root origin with strict host/origin checks | Generic configuration checked synthetically; subdirectory deployments not claimed |
| UI | PC and Touch | Standalone rendering not run |
| Scheduling | Plugin-owned CLI; no frontend heavy work | Local candidate implementation; real cron/runtime verification pending |
| DX X3.x | Not supported by the initial contract | No compatibility claim |

Native interactions, task adapters and credit rewards have separate gates. The plugin will not silently overwrite official core files. Missing integrations must disable affected backend operations.

Customized existing deployments are not an upgrade target for the first generic package. Do not install a generic candidate over an existing customized integration without a reviewed data and compatibility path.
