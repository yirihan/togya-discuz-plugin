# Alpha Upgrade Notes / Alpha 升级说明

These are experimental fresh-lineage candidates, not a migration path for an existing customized integration or a production-support claim. Use a disposable test instance first. Stop its scheduler and keep native task/interaction/credit-grant features closed.

## Alpha.6 And Earlier To Alpha.7: Private Images

Earlier candidates could copy member-only images into `data/attachment/forum/pintogya/` as ordinary static files. Knowing that URL bypasses forum authentication. Disabling the plugin does not remove those files. Do not use older candidates with real private member images.

Alpha.7 stores new member images only in the private cache and serves them through authenticated same-origin POST with current binding, forum-reading and stage permissions. The browser never uses an old member static URL. Public images remain ordinary public attachments.

1. Stop the old scheduler/worker before replacing code. Preserve the private cache and its job identities; do not clear it as part of upgrade. Protect any existing affected static directory from public access until its contents have been reviewed; changing plugin code alone does not revoke old static URLs or CDN copies.
2. Upload the matching plugin subtree, run native AdminCP upgrade and refresh all native cache types. Keep optional tasks/interactions/rewards closed. No schema change is added in Alpha.7.
3. As the normal web-service user, run the documented bounded maintenance flow from [installation instructions](INSTALL.md). Each display-cache pass retires at most 64 known private mirrors. It verifies the request-derived filename, owner, regular file, retained private bytes and SHA-256, writes evidence under private-cache `retired-member-images/`, then removes only that verified public mirror. It never deletes the retained private image. Sanitized results report `retiredPrivateImages`; inspect task errors as well as counts.
4. A zero retirement count is **not** proof that all historical files are safe. Missing job identity/private bytes, altered files, old versions of an image or CDN/browser copies require explicit operator review. Do not delete unidentified business attachments or change permissions blindly. Keep the affected static directory inaccessible until unknown copies are accounted for, and purge identified exposed URLs from any CDN under your control.
5. Verify guest and unauthorized requests cannot obtain private image bytes; authorized images must still work through the protected endpoint. Only then resume the new scheduler. Do not resume an older worker alongside it.

Old versions are not a safe code-only rollback for private media. Pause synchronization and keep affected static URLs blocked if recovery is necessary. Private retained bytes and pre-removal evidence support investigation; they cannot retract copies already downloaded by someone else.

中文：旧版可能把会员图片复制到公开附件地址，停用插件也不会撤销旧链接。升级前停旧任务、保留私有缓存与身份记录，并隔离受影响静态目录；升级后有界维护仅在哈希核对和证据落盘后移除已知副本，保留私有原件。记录丢失、历史旧图和 CDN 副本必须人工核对，清理数为 0 不代表没有遗留。新图片经当前会员与原生阅读权限检查后以 POST 返回，不再生成会员图片静态地址。

## Alpha.3 To Alpha.4

The old first-post marker ended in `pintogya:<digest>`. Stock DX looks up the entire trailer as a plugin identifier, so it could create a topic successfully without dispatching the activity renderer. Alpha.4 writes an exact `pintogya` trailer and stores the digest before it. The old digest format remains recognizable for diagnostics/reconciliation; it is not treated as a valid new writer format.

Migration `0013_native_thread_marker.php` runs during the native install/upgrade flow after the plugin tables exist. It converts only an exactly matching old machine marker on a mapped first post whose topic, forum and author match. Comparison is binary, including trailing spaces. It does not replace edited content, change visibility/permissions, delete data or issue credits.

The migration validates InnoDB for the affected tables, inspects only mapped post-table shards, and collects at most 1,000 eligible posts across at most 64 shards before writing. Exceeding either bound stops with an error before conversion; do not repeatedly retry, raise the bound blindly or replace all forum posts. Record the blocker and plan a bounded operator-reviewed migration instead.

1. For a non-disposable instance, make and verify a database backup before the approved upgrade. Git history alone cannot roll back a post-data format change.
2. Stop the instance's scheduler. Upload only the matching `upload/` subtree; never overwrite the official core or copy a whole source checkout into a webroot.
3. Run the native AdminCP plugin upgrade. Verify the displayed and declared plugin versions agree and the installation identity is preserved.
4. Refresh all cache types through native AdminCP, including thread/template-related caches. Verify existing mapped topics on PC and Touch before restarting the scheduler.
5. If the bound is exceeded or an edited/mismatched topic remains, retain its content and report it. Do not delete topics, reset the installation UUID or disable matching checks to force success.

After conversion, code-only rollback to Alpha.3 is not a supported recovery: that version expects the old marker and has the original dispatch defect. Recovery of a non-disposable instance requires a coordinated code/database restore from the pre-upgrade backup. Alpha.3 assets remain immutable for provenance, not as a recommended runtime.

## 当前限制

本版本不是 RC/正式版。升级只转换匹配原始机器标记的已映射首帖，不覆盖用户编辑后的正文、不改权限、不删除数据、不发积分。超过 1,000 条候选或 64 个分表时，在写入前停止，必须另行制定有界迁移，不能循环重试。

Alpha.4 下载包中的普通会员编辑器尚未完成官方钩子适配，界面、提交/完成钩子、编辑器 API 和恢复发布路由均关闭。Alpha.5 源码检查点及后续候选已加入官方模型前置钩子；八类隔离原生模型场景通过，另获批的一次固定 Alpha.5 控制器 HTTP 复验四项通过，原三次失败记录保留，不再追加。Alpha.6 修复附件父目录及机器标记输出，但存在上述私有媒体问题。所有候选仍不是生产支持版本；真实扫码批准未验。所有原生任务、互动和积分发放仍由后端关闭；非零奖励的新发布也会拒绝，不能删除开关来“恢复功能”。
