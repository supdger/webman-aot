## Why

当前公开仓库、安装包、命令、私有目录和机器格式使用 `webman-aot`，不能明确表达它是构建工具。用户已确定统一更名为 `webman-aot-builder`，并在下一版本实际发布，使下载、安装、构建、更新和文档呈现同一个产品身份。

## What Changes

- **BREAKING**：下一版本的主命令、入口文件、PHP 命名空间、环境变量、用户数据根目录、项目缓存标识、包名和自有机器格式统一使用 `webman-aot-builder`／`WEBMAN_AOT_BUILDER`／`WebmanAotBuilder`。
- 将 GitHub 仓库更名为 `supdger/webman-aot-builder`，同步源码内仓库地址、更新源、构包脚本、CI、组件锁和使用文档；已发布的 v0.1.3 资产保持原样。
- 新安装器使用独立的新用户目录，不能从旧目录复用未经重新验证的缓存；旧版 `self-update` 不宣称完成命令及用户目录迁移。提供明确的卸载、重新安装和恢复说明。
- 确认远端已有版本与发布资产后确定未占用版本（目标 v0.2.0），重建或验证重封装两平台组件及安装包，更新摘要和签名关联数据，完成验收后发布。

## Capabilities

### New Capabilities

- `builder-identity-and-installation`：用户可从新仓库下载、安装、执行、更新和卸载名称一致的构建工具，并在命名迁移边界获得准确提示。
- `builder-release-integrity`：新版本安装包与锁定组件保持版本、名称、哈希、更新来源和发布资产一致，发布前可复核。

### Modified Capabilities

无。仓库首次采用 OpenSpec，当前没有现行 capability spec。

## Impact

影响 CLI 入口和 PHP 源码命名空间、安装/卸载与自更新、平台目录布局、缓存和诊断格式、构包与组件生成脚本、锁文件、GitHub Actions、Mac 与 Windows 安装包、Linux 产物验证说明，以及 GitHub 仓库和 Release。历史 v0.1.3 发布资产不重写；仓库更名、推送和发布是远端操作，必须在固定候选与发布验收通过后执行。
