# Webman AOT Builder

在 macOS Apple Silicon 或 Windows x64 开发机上，把 Webman / SaiAdmin 项目编译成
面向 Linux amd64 的全静态程序。工具安装在开发机上，不需要装进 Webman 项目，
构建时也不需要 Docker。

## 下载 v0.2.1

按**开发电脑的系统**选择一行，再从轻量包和完整包中选一个。下列是 v0.2.1
的准确资产链接；**Release 发布前链接尚不可用**。下载后，对照
[SHA256SUMS](https://github.com/supdger/webman-aot-builder/releases/download/v0.2.1/SHA256SUMS)
中相同文件名的一行核对 SHA-256。不要下载 GitHub 自动生成的 `Source code (zip)`。

| 开发电脑 | 轻量包 | 完整包（安装时离线准备组件） |
| --- | --- | --- |
| macOS Apple Silicon | [下载 `.tar.gz`](https://github.com/supdger/webman-aot-builder/releases/download/v0.2.1/webman-aot-builder-0.2.1-macos-arm64.tar.gz) | [下载完整 `.tar.gz`](https://github.com/supdger/webman-aot-builder/releases/download/v0.2.1/webman-aot-builder-0.2.1-full-macos-arm64.tar.gz) |
| Windows x64 | [下载 `.zip`](https://github.com/supdger/webman-aot-builder/releases/download/v0.2.1/webman-aot-builder-0.2.1-windows-x86_64.zip) | [下载完整 `.zip`](https://github.com/supdger/webman-aot-builder/releases/download/v0.2.1/webman-aot-builder-0.2.1-full-windows-x86_64.zip) |

完整包内含锁定的编译组件，可在安装时离线准备；轻量包须在首次使用时联网下载、
校验并准备同一组件。两种包在同一平台运行相同的安装脚本。

如果使用的是 [v0.2.0 安装包](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.0)，
请按 [v0.2.0 安装指南](https://github.com/supdger/webman-aot-builder/wiki/Install)
操作；该版本的命令是 `webman-aot-builder`。下面的 `webman-aot` 步骤适用于
v0.2.1。

## v0.2.1 安装与使用步骤

### 1. 在开发电脑安装

**Windows x64：**下载适合 Windows 的安装包并解压。在资源管理器中打开
**直接包含 `install.ps1` 的解压目录**，在地址栏输入 `powershell` 并回车，
打开 Windows PowerShell（不是 CMD）。在该窗口运行：

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\install.ps1
if ($LASTEXITCODE -ne 0) { throw 'Installation failed.' }
```

安装脚本会校验包内文件。默认安装在
`%LOCALAPPDATA%\webman-aot-builder`，命令入口在其 `bin` 目录。
关闭并重新打开 PowerShell，确认运行的是新版安装的命令：

```powershell
& "$env:LOCALAPPDATA\webman-aot-builder\bin\webman-aot.cmd" version
Get-Command webman-aot | Select-Object -ExpandProperty Source
```

`version` 应显示 `0.2.1`；`Get-Command` 的路径应是上述
`webman-aot-builder\bin\webman-aot.cmd`。如果它仍指向旧版安装位置，
先处理用户 `PATH` 中的旧版路径，再运行下文的 `webman-aot` 命令。

**macOS Apple Silicon：**下载适合 Mac 的安装包并解压。打开「终端」，输入
`cd `（末尾有空格），把**直接包含 `install.sh` 的解压目录**从 Finder
拖进终端窗口，按回车，然后运行：

```sh
./install.sh
"$HOME/.local/bin/webman-aot" version
```

安装脚本会校验包内文件。默认安装在
`~/Library/Application Support/webman-aot-builder`，命令入口在 `~/.local/bin`。
关闭并重新打开终端，运行 `command -v webman-aot`；它应指向
`~/.local/bin/webman-aot`，且 `webman-aot version` 应显示 `0.2.1`。
如果命令仍指向旧版安装位置，先处理 `PATH` 中的旧版路径。

### 2. 进入自己的 Webman 项目根

**离开安装包解压目录。**在自己的 Webman 项目根，应能直接看到
`composer.json`、`composer.lock`、`start.php` 和 `app/` 文件夹，
且 `composer.lock` 锁定 `workerman/webman-framework`。不要在本工具的源码仓库、
`dist/source-build/` 或安装包解压目录运行下面的命令。

- Windows：在资源管理器打开自己的 Webman 项目根，在地址栏输入 `powershell`
  并回车；运行 `Get-Location` 确认当前目录。
- Mac：在「终端」输入 `cd `，把自己的 Webman 项目文件夹从 Finder 拖进去，
  按回车；运行 `pwd` 确认当前目录。

### 3. 检查、准备组件、构建和验证

在**上述项目根目录**运行：

```text
webman-aot doctor
```

轻量包首次运行 `doctor` 时，**只有项目检查通过才会开始**联网下载、校验和
准备组件；终端会显示下载与准备进度。完整包安装时已离线准备组件。
等待输出 `Result: healthy`，再在同一项目根目录依次运行：

```text
webman-aot build
webman-aot verify
```

SaiAdmin 项目的构建命令改为 `webman-aot build --profile=saiadmin`，
详见 [SaiAdmin 兼容说明](https://github.com/supdger/webman-aot-builder/wiki/SaiAdmin-Compatibility)。
`build` 成功后项目目录会出现 `dist-aot/`；`verify` 检查产物结构与静态属性。
将整个 `dist-aot/` 部署到 Linux amd64 目标机后，仍需按
[Linux 目标机验收](https://github.com/supdger/webman-aot-builder/wiki/Linux-Acceptance)
检查启动、数据库和业务接口。

如果输出 `[ERROR] project:`，先返回第 2 步确认
当前目录和项目结构；**在错误目录原地重跑或加 `--repair` 不会开始下载。**
若项目检查通过但下载失败，保留错误原文；网络恢复后在同一个项目根重跑
`webman-aot doctor`，已校验的组件可复用，部分下载会尝试续传。
网络持续受限时可安装对应平台的完整包。

v0.2.1 的 `webman-aot` 命令使用 `webman-aot-builder` 用户数据目录；
旧版同名命令可能仍在 `PATH` 中。安装后先按第 1 步确认实际命令位置与版本，
避免调用到旧版。

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
[归属说明](NOTICE.md)。问题请提交到
[Issues](https://github.com/supdger/webman-aot-builder/issues)。
