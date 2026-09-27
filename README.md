# Webman AOT Builder

在 macOS Apple Silicon 或 Windows x64 开发机上，把 Webman / SaiAdmin 项目编译成
面向 Linux amd64 的全静态程序。工具安装在开发机上，不需要装进 Webman 项目，
构建时也不需要 Docker。

## 下载 v0.2.0

请下载与你的**开发机**系统匹配的安装包。完整包已经包含锁定的编译组件；
轻量包会在首次使用时联网下载并校验同一组件。

| 开发机 | 轻量包 | 完整包（可离线准备组件） |
| --- | --- | --- |
| macOS Apple Silicon | [下载 `.tar.gz`](https://github.com/supdger/webman-aot-builder/releases/download/v0.2.0/webman-aot-builder-0.2.0-macos-arm64.tar.gz) | [下载完整 `.tar.gz`](https://github.com/supdger/webman-aot-builder/releases/download/v0.2.0/webman-aot-builder-0.2.0-full-macos-arm64.tar.gz) |
| Windows x64 | [下载 `.zip`](https://github.com/supdger/webman-aot-builder/releases/download/v0.2.0/webman-aot-builder-0.2.0-windows-x86_64.zip) | [下载完整 `.zip`](https://github.com/supdger/webman-aot-builder/releases/download/v0.2.0/webman-aot-builder-0.2.0-full-windows-x86_64.zip) |

以下命令以**完整包**为例。在 macOS 终端中运行：

```sh
mkdir -p "$HOME/Downloads/webman-aot-builder-0.2.0"
cd "$HOME/Downloads/webman-aot-builder-0.2.0"
curl -fLO https://github.com/supdger/webman-aot-builder/releases/download/v0.2.0/webman-aot-builder-0.2.0-full-macos-arm64.tar.gz
tar -xzf webman-aot-builder-0.2.0-full-macos-arm64.tar.gz
./install.sh && "$HOME/.local/bin/webman-aot-builder" version
```

在 Windows **PowerShell** 中运行：

```powershell
$ErrorActionPreference = 'Stop'
$url = 'https://github.com/supdger/webman-aot-builder/releases/download/v0.2.0/webman-aot-builder-0.2.0-full-windows-x86_64.zip'
$archive = Join-Path $env:USERPROFILE 'Downloads\webman-aot-builder-0.2.0-full-windows-x86_64.zip'
$package = Join-Path $env:TEMP 'webman-aot-builder-0.2.0'
Invoke-WebRequest -Uri $url -OutFile $archive
Expand-Archive -LiteralPath $archive -DestinationPath $package -Force
powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $package 'install.ps1')
if ($LASTEXITCODE -ne 0) { throw 'Installation failed.' }
& (Join-Path $env:LOCALAPPDATA 'webman-aot-builder\bin\webman-aot-builder.cmd') version
```

安装脚本会校验包内文件。macOS 默认安装到
`~/Library/Application Support/webman-aot-builder`，命令在 `~/.local/bin`；
Windows 默认安装到 `%LOCALAPPDATA%\webman-aot-builder`，安装器会将其 `bin`
目录加入用户 PATH。打开新终端后，在自己的 Webman 项目目录（包含 `webman`
和 `composer.json`）运行：

```sh
webman-aot-builder doctor
webman-aot-builder build
webman-aot-builder verify
```

SaiAdmin 项目将构建命令改为 `webman-aot-builder build --profile=saiadmin`。
`doctor` 显示 `Result: healthy` 后再构建；产物在项目的 `dist-aot/`。
`verify` 检查产物结构，部署后仍需在目标 Linux 机器验收启动、数据库和业务接口。
详细步骤、校验值、故障处理及卸载方法见
[安装与使用说明](https://github.com/supdger/webman-aot-builder/wiki/Install)。

## 源码、兼容与许可

[Wiki 首页](https://github.com/supdger/webman-aot-builder/wiki/Home)汇集使用说明；
[从源码制作安装包](https://github.com/supdger/webman-aot-builder/wiki/Build-from-source)
供维护者使用。仓库源码 ZIP 不是可直接安装的工具。Mac 和 Windows 安装包都面向
开发机，生成的 `dist-aot/` 才面向 Linux amd64。

当前验证组合、SaiAdmin 兼容范围和目标机验收边界见
[验证记录](https://github.com/supdger/webman-aot-builder/wiki/Verification)、
[SaiAdmin 兼容说明](https://github.com/supdger/webman-aot-builder/wiki/SaiAdmin-Compatibility)、
[Linux 验收说明](https://github.com/supdger/webman-aot-builder/wiki/Linux-Acceptance)及
[v0.2.0 发布记录](https://github.com/supdger/webman-aot-builder/wiki/Release-0.2.0)。
原创代码使用 [MIT 许可证](LICENSE)；第三方组件保留各自许可，见
[归属说明](NOTICE.md)。

v0.1.3 使用旧包名和旧命令 `webman-aot`，其
[历史安装包](https://github.com/supdger/webman-aot/releases/tag/v0.1.3)
不会自动迁移到 v0.2.0 的安装目录。问题请提交到
[Issues](https://github.com/supdger/webman-aot-builder/issues)。
