# Alpha Upgrade Notes / Alpha 升级说明

These are experimental fresh-lineage candidates, not a migration path for an existing customized integration or a production-support claim. Use a disposable test instance first. Stop its scheduler and keep native task/interaction/credit-grant features closed.

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

普通会员编辑器尚未完成官方钩子适配，界面、提交/完成钩子、编辑器 API 和恢复发布路由均关闭；已验证的后台官方示例路径不代表会员发帖或真实扫码链路通过。请保留这个限制，不要删除后端开关来“恢复功能”。
