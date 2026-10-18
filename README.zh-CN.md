# Togya Plugin

把 Togya 账号连接、原生任务与共同学习活动带进 Discuz X5。

[English](README.md) · [下载](https://github.com/yirihan/togya-discuz-plugin/releases/tag/v1.0.0-x5) · [安装说明](docs/INSTALL.zh-CN.md) · [功能一致性](docs/SOURCE_FIDELITY.md)

## 功能

- Togya 扫码批准后连接论坛账号，并支持解绑。
- 六个原生任务入口，保留原有周期、条件和教程配图。
- 原生活动主题，支持 PC/Touch 阶段、进度、参与者和打卡展示。
- 原生评论、评分及配置奖励，沿用原有权限、事务和防重复发放规则。
- 本地展示缓存、有界后台同步；打开主题不实时向 Togya 拉取活动正文。
- 通用站点名称、数据库绑定的独立安装身份，以及插件自有维护入口。

**版本：`1.0.0-x5`。** 按本地权威实现脱敏发行，不重新设计或缩减功能。图片保留本地规则：由内容页展示普通 DX 静态附件地址。早期实验 Alpha 的额外关闭开关和图片访问改造不代表本版行为。

## 安装

阅读[安装说明](docs/INSTALL.zh-CN.md)，上传完整 `upload/` 目录，通过官方 AdminCP 安装并设置版块、用户组、积分及密钥管理，更新官方缓存，再以 Web PHP 相同用户配置后台维护。

包内增加插件、六个指定的自定义任务文件和任务图标，不包含整套官方核心、客户配置、凭据或业务数据。任务仍在原生任务中心配置；上传文件不会自动创建带金额的任务或发放积分。

面向 **Discuz X5、PHP 8.2+、HTTPS、InnoDB**。另见[兼容范围](docs/COMPATIBILITY.md)、[发布状态](docs/STATUS.md)、[升级说明](docs/UPGRADING.md)、[隐私说明](docs/PRIVACY.md)及[合成预览](docs/PREVIEW.md)。不宣称已经独立验证 X3.x、MariaDB、任意子目录/共享主机环境或全部 DX 版本。

## 许可证

自有代码采用 [0BSD](LICENSE)，允许自由使用、修改和再分发，包括商业用途，不要求署名或保留版权声明，不提供担保。第三方库、商标与平台徽章保留各自许可说明。Togya Logo 与示例插画已获再分发授权，详见包内 `THIRD_PARTY.md`。

反馈问题请提供版本、合成示例与脱敏错误，不要上传凭据、Cookie 或生产私密数据。
