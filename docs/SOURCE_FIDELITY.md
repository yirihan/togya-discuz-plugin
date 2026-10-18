# Source Fidelity / 功能与规则一致性

The authoritative local implementation defines the feature set and business rules. Version `1.0.0-x5` anonymizes customer identity and adapts installation/native interfaces to DX; it does not invent feature restrictions or substitute a smaller product.

本地权威实现决定功能与规则。通用版只做脱敏和必要的 DX 接入适配，不另设功能关闭、零金额限制或新的奖励条件。本地功能取舍与既有实际测试作为发布基线，适配检查不冒充对所有 DX 版本的实际验证。

## Reviewed Difference Classes

| Class | Files / scope | Boundary |
| --- | --- | --- |
| Identity and presentation | Language packs, site-profile labels, browser/device prefix, menu/log/header names, static resource versions | Resolve forum display name from existing DX settings; no fixed customer names, databases or hosts |
| Tenant isolation | `installation.php`, instant-worker/admin/status/rule scope checks, install/upgrade, migration lock names | Stable installation UUID bound to database/prefix replaces a customer allowlist; amounts, source facts, transaction and duplicate-entitlement checks remain. Existing customized databases are not silently adopted |
| Standalone maintenance | `maintenance.php`, `maintenance_runner.php`, CLI entry, keepalive adapter | Replace the private external scheduler dependency with owned task entry points; preserve native operator settings and bounded process/lock behavior. External scheduler installation/settings are not automatically imported |
| Stock DX integration | `native_composer.php`, `activity_native_post.php`, thread adapter, marker migration, draft validation | Stock before-model hooks and exact plugin trailer replace customized-core assumptions; native moderation remains effective. No complete official-core files are distributed |
| Native tasks and tutorials | Six owned task classes, shared icon, nine original example images, guide renderer | Original task rules, periods and administrator restrictions retained; only customer copyright identity is anonymized. Named allowlist packaging excludes unrelated/official task classes |
| Uninstall | `check.php`, `uninstall_guard.php`, `uninstall.php` | Preserve the original refusal whenever any of the original twelve checked tables contains records. Only that original empty-table list is eligible for cleanup; native pre-delete validation protects registration. Do not clear business tables to bypass refusal |
| Image behavior | Display-cache/storage/member adapters and `activity-view.js` | Restored local behavior as explicitly directed: authorized feeds render ordinary DX static attachment URLs. No additional authenticated-Blob transport or automatic public-mirror retirement is added. Historical Alpha.7 differs and is not the final behavior baseline |

## Evidence And Limits

- Nineteen selected rule/guide/image method bodies match authority PHP tokens, ignoring only comments/whitespace. This is scoped equivalence, not an independent repeat of the owner's local end-to-end testing.
- Six task adapters match their local source after only the customer copyright label is replaced. Nine guide images and the task icon are byte-identical; guide WebP chunks contain no EXIF/XMP. Visual review found example identities, not customer account screenshots.
- Named-method inventory comparison is a review aid: matching methods include bundled third-party code. It does not establish complete dynamic call coverage, safety of every global statement, or end-to-end behavior.
- Uninstall policy checks use a synthetic SQL adapter: each of the twelve nonempty tables refuses cleanup, and the empty cleanup list matches the original. No real database mutation, native uninstall retry or prompt-capture pass is implied.
- Historical Alpha gates remain historical. Changing a function back to its authoritative rule does not turn an old disabled-feature test into successful task completion or credit issuance. Exhausted native lifecycle/controller budgets are not reset.

Existing native-hook evidence is reused where applicable. Task and financial rules follow the local implementation; no new feature gate or repeated end-to-end acceptance is introduced for this anonymized distribution. Versions/environments not exercised are not claimed as independently verified.
