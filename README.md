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

两种包使用同一个安装脚本，区别是下载文件和校验值。只执行与你的开发机及所选包对应的一段命令。

### macOS Apple Silicon：轻量包

```sh
(
  set -e
  mkdir -p "$HOME/Downloads"
  cd "$HOME/Downloads"
  package=webman-aot-builder-0.2.0-macos-arm64.tar.gz
  curl -fL --progress-bar "https://github.com/supdger/webman-aot-builder/releases/download/v0.2.0/$package" -o "$package"
  printf '%s  %s\n' d3fc01fd706fc200f07fca489990a4c61ca3ec6c341d5b7714a001654e2917e9 "$package" | shasum -a 256 -c -
  mkdir -p webman-aot-builder-0.2.0-small-install
  tar -xzf "$package" -C webman-aot-builder-0.2.0-small-install
  cd webman-aot-builder-0.2.0-small-install
  ./install.sh
  "$HOME/.local/bin/webman-aot-builder" version
)
```

### macOS Apple Silicon：完整包

```sh
(
  set -e
  mkdir -p "$HOME/Downloads"
  cd "$HOME/Downloads"
  package=webman-aot-builder-0.2.0-full-macos-arm64.tar.gz
  curl -fL --progress-bar "https://github.com/supdger/webman-aot-builder/releases/download/v0.2.0/$package" -o "$package"
  printf '%s  %s\n' c49a8d3e4fc482914d5a433fbda5e3f88262327e537baaf59ffa45ed9d450e10 "$package" | shasum -a 256 -c -
  mkdir -p webman-aot-builder-0.2.0-full-install
  tar -xzf "$package" -C webman-aot-builder-0.2.0-full-install
  cd webman-aot-builder-0.2.0-full-install
  ./install.sh
  "$HOME/.local/bin/webman-aot-builder" version
)
```

### Windows x64：轻量包

```powershell
$ErrorActionPreference = 'Stop'
$package = 'webman-aot-builder-0.2.0-windows-x86_64.zip'
$archive = Join-Path $env:TEMP $package
Invoke-WebRequest -Uri "https://github.com/supdger/webman-aot-builder/releases/download/v0.2.0/$package" -OutFile $archive
$actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $archive).Hash.ToLowerInvariant()
if ($actual -ne '375d75b64af0cfc2941000450ae87109276a02734484dcfa836de960e7612ffa') { throw "SHA-256 mismatch: $archive" }
Write-Output "SHA-256 OK: $archive"
$extract = Join-Path $env:TEMP 'webman-aot-builder-0.2.0-small-install'
Expand-Archive -LiteralPath $archive -DestinationPath $extract -Force
powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $extract 'install.ps1')
if ($LASTEXITCODE -ne 0) { throw 'Installation failed.' }
& (Join-Path $env:LOCALAPPDATA 'webman-aot-builder\bin\webman-aot-builder.cmd') version
```

### Windows x64：完整包

```powershell
$ErrorActionPreference = 'Stop'
$package = 'webman-aot-builder-0.2.0-full-windows-x86_64.zip'
$archive = Join-Path $env:TEMP $package
Invoke-WebRequest -Uri "https://github.com/supdger/webman-aot-builder/releases/download/v0.2.0/$package" -OutFile $archive
$actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $archive).Hash.ToLowerInvariant()
if ($actual -ne '0c5f73a7f562582ad73f77594526e508b93865b40fcc063b864389f784d821a5') { throw "SHA-256 mismatch: $archive" }
Write-Output "SHA-256 OK: $archive"
$extract = Join-Path $env:TEMP 'webman-aot-builder-0.2.0-full-install'
Expand-Archive -LiteralPath $archive -DestinationPath $extract -Force
powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $extract 'install.ps1')
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
轻量包首次运行 `doctor` 时会自动联网下载并校验组件，无需手动下载 `-components.zip`；
完整包安装时已离线准备组件。`doctor` 显示 `Result: healthy` 后再构建；
产物在项目的 `dist-aot/`。
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
