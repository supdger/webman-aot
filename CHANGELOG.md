# 更新日志

本文按 GitHub 正式 Release 整理；日期采用 Release 的 UTC 发布日期。候选版草稿不列为正式版本。

## Unreleased（开发快照，尚未发布）

- 新增统一引导入口：从源码选择轻量或完整包、确认安装后选择项目目录，自动执行构建与产物验证；独立 setup 入口会下载并核验对应安装包。该功能尚不属于公开 v0.2.3 Release。
- 修复源码快照和构建镜像因任意目录层级的 `.DS_Store` 文件而误判源码变化的问题；发现真实源码变化时仍会中止，并显示具体相对路径和重试建议。

## [v0.2.3](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.3) — 2026-09-28

- 修复 Windows Git 将 SaiAdmin 的 `support/bootstrap.php` 检出为 CRLF 时，构建器误判启动源码不兼容的问题。构建过程只规范化隔离构建镜像中的锁定源码，不改项目文件；真实内容差异仍会被拒绝。无需调整项目文件或全局 Git 设置。

## [v0.2.2](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.2) — 2026-09-27

- `webman-aot doctor` 可在任意目录运行，并自动下载、校验和准备编译组件；轻量包首次运行时联网准备，完整包可在安装时离线准备。
- `doctor --check` 和 `doctor --json` 仍可用于只检查状态，不触发自动准备。

## [v0.2.1](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.1) — 2026-09-27

- 安装后的命令改为简短的 `webman-aot`。升级后重新打开终端或 PowerShell，并运行 `webman-aot version` 确认使用的是新版本。
- 改进锁定编译器的路径引用与安全选择，减少宿主 `PATH` 或项目目录同名文件对编译器调用的干扰；该版验收也覆盖了带空格的安装路径。

## [v0.2.0](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.0) — 2026-09-27

- 产品更名为 Webman AOT Builder，安装后的命令为 `webman-aot-builder`；安装包和数据目录也随之更名。Linux 构建输出仍为 `dist-aot/`。v0.2.0 使用独立数据目录，不会读取或迁移 v0.1.3 的用户数据。
- v0.2.0 未提供可信的自更新清单和签名密钥；从 v0.1.3 升级时应下载并安装本版安装包，不要依赖旧版 `self-update`。
- 轻量包需联网准备组件，完整包内置锁定组件，可离线准备。

## [v0.1.3](https://github.com/supdger/webman-aot-builder/releases/tag/v0.1.3) — 2026-09-27

- 增加轻量与完整安装包。完整包内置精简编译组件，安装后无需再运行 `doctor --repair`；轻量包在首次 `doctor` 或 `build` 时获取组件。
- 两种包使用相同的按平台锁定组件，并逐文件校验；网络不便时可选择完整包。

## [v0.1.2](https://github.com/supdger/webman-aot-builder/releases/tag/v0.1.2) — 2026-09-26

- 修复 Windows 源码构建的安装包与 Release 包比较时的元数据误报。比较时可从参考包自动读取修订标识，无需手工查找或输入提交号。

## [v0.1.1](https://github.com/supdger/webman-aot-builder/releases/tag/v0.1.1) — 2026-09-26

- 改进首次准备工具链时的下载与进度提示；`doctor --repair` 会显示组件进度，安装器会自动校验并报告组件文件。
- Windows 源码构包流程可下载锁定输入、生成安装包并与已发布 ZIP 对照。
- 补齐初次安装、卸载和源码构包说明。

## [v0.1.0](https://github.com/supdger/webman-aot-builder/releases/tag/v0.1.0) — 2026-09-26

- 首个公开安装版，支持在 Apple Silicon Mac 或 Windows x64 开发机上构建 Linux amd64 musl 全静态程序。
- 提供 `doctor`、`build` 和 `verify` 命令；首次需准备锁定的编译组件，再构建并检查 `dist-aot/`。普通 Webman 项目无需安装 AOT Composer 插件。
- 支持范围和已验证依赖组合见该版 [Release 说明](https://github.com/supdger/webman-aot-builder/releases/tag/v0.1.0)。
