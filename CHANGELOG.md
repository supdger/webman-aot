# 更新日志

本文记录各版本的变更；正式发布状态以 GitHub Release 为准，已发布版本日期采用 Release 的 UTC 发布日期。

## [v0.3.2](https://github.com/supdger/webman-aot-builder/releases/tag/v0.3.2)

- 支持锁定的 Carbon 3.14.1，修复 SaiAdmin 项目因 Carbon 版本拒绝、生成配置选择或生成代码不兼容而中止构建的问题。
- 补齐 DeepClone 1.42.0 编译所需的闭包绑定、引用存储和反射行为，修复类被跳过及生成代码无法编译的问题；保留完整 DeepClone 适配，不通过排除依赖绕过失败。`Closure::call()` 仍不支持。
- 使用本项目从锁定官方输入重建的 PHPX 静态 SDK，记录来源、补丁、库与头文件摘要，并由编译组件和源码构建入口校验。新增衍生 SDK 素材供维护者重建；普通安装继续使用 setup。
- 本版需要更新安装包及对应编译组件，旧组件不能作为新版 SDK 使用。项目源码保持不变；编译与本机校验通过仍需另行完成 Linux 启动、数据库和业务验收。

## [v0.3.1](https://github.com/supdger/webman-aot-builder/releases/tag/v0.3.1)

- 修复 Windows 与 macOS 安装引导进入项目构建后，阶段和编译日志不能及时显示的问题。现在会持续显示准备阶段、编译器的逐文件进度与真实完成数；没有新输出时显示等待时间，完成或失败时保留耗时、原始错误和退出码。
- 增加锁定 `symfony/polyfill-deepclone v1.42.0` 对 PHP 8.4 静态目标的适配，修复其条件声明触发的 stray code 构建错误；适配只作用于隔离构建镜像，包版本或源码不匹配时拒绝应用。
- 下载说明逐项解释 setup、轻量包、完整包、编译组件、校验文件及 GitHub 自动源码归档的用途，明确首次安装的推荐入口。
- 构建进度修复已通过 Windows 和 macOS 的真实项目菜单、编译与产物校验；这不代表所有 SaiAdmin 依赖组合或 Linux 业务运行已通过验收。

## [v0.3.0](https://github.com/supdger/webman-aot-builder/releases/tag/v0.3.0)

- 新增一次启动的 CLI 引导流程：源码入口 `build.command` / `build.cmd` 自动准备材料和制作安装包；独立 setup 自动下载安装包。用户选择轻量或完整包、确认安装、选择项目目录后，工具自动完成校验、安装、新版本检查、项目构建及产物验证。
- 构建、下载和安装显示可理解的步骤与实际状态。成功显示结果及下一步，失败显示原因、恢复建议和本机日志位置；项目失败可选择重试、重选目录或结束。
- 修复 Windows 中文安装路径中的 PHP 配置和扩展加载问题，保留实际下载失败退出码；独立 Windows setup 使用 CRLF，避免 CMD 解析错误。
- 修复任意目录层级的 `.DS_Store` 导致源码快照和构建镜像误判的问题；真实源码变化仍会中止，并显示变化路径和重试建议。
- 安装和项目构建的验证针对开发机上的包身份、构建产物结构和完整性；Linux 部署及业务运行需另行验收。

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
