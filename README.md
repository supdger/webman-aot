# Webman AOT

Webman AOT 是一个独立的全局命令行工具项目，目标是在 macOS ARM64 和
Windows x64 主机上构建 Linux amd64 musl 全静态 Webman/SaiAdmin
可执行文件。

全静态 SDK 可行性门和 Mac/Windows 跨主机最小构建已经通过。当前继续实现
全局 CLI、项目发现和 SaiAdmin 适配；在业务及目标 Linux 验收完成前，本项目
仍不会把“生成了二进制”标记为可发布。

## 开发入口

```bash
php tools/toolchain.php self-check
php tests/run.php
php bin/webman-aot.php --help
```

工具链脚本只读写本仓库的 `build/`、`dist/` 和后续定义的用户私有工具目录，
不要求目标 Webman 项目安装 Composer 插件。

发布版启动器只使用用户私有目录中的运行时和应用代码：

- macOS ARM64：`~/Library/Application Support/webman-aot`
- Windows x64：`%LOCALAPPDATA%\webman-aot`

源码开发时直接运行 `php bin/webman-aot.php`；最终安装包会把私有 PHP、
应用版本和启动器放入上述目录，不读取目标项目的 PHP 或 Composer AOT 包。

每次命令运行按 `bootstrap`、`dispatch`、`execute` 记录阶段状态。用户私有
`logs/<run-id>/` 中包含结构化 `events.jsonl` 和人类可读 `run.log`；失败时
额外生成 `diagnostic.json`，并使用稳定退出码区分用法错误、不可用依赖、
内部错误和配置错误。

## Windows 跨主机复现

在 Windows x64 PowerShell 中检出同一提交后直接运行：

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\windows-replay.ps1
```

脚本从 `toolchain.lock.json` 下载并校验锁定组件，将下载缓存和每次构建工作区
放在 `%LOCALAPPDATA%\webman-aot`。它不调用 Docker、winget、系统 PHP 或 GUI
安装器，并分别比较 Mac 基线的规范化输入摘要和 ELF SHA-256。只有两项都一致
才返回成功。锁定组件使用固定 partial 文件、有界网络重试和断点续传；下载
完成后仍须通过 SHA-256 才会原子提升为可用缓存。

Windows ZIP 与源码归档统一由系统自带 `tar.exe` 解包，避免 PowerShell
`Expand-Archive` 在包含完整 Composer 依赖的 TypePHP 包上长时间挂起。

仓库的 `Windows full-static replay` workflow 在 Windows Server 2022 x64 原生
runner 上执行同一脚本，用于持续验证跨宿主 SDK 与 ELF 一致性。该 workflow
不替代后续干净实体 Windows 用户账号下的安装、构建和卸载验收。
