# 从源码制作工具安装包

这页给想自己构建、审查或修改 Webman AOT 工具的人。只想编译自己的
Webman 项目，请返回[首页的安装路线](../README.md#第-1-步下载并安装工具)；
你不需要克隆本仓库。

## 源码能做什么

本仓库提供工具源码、两个平台的安装脚本、打包脚本和
[锁定的运行时版本及 SHA-256](../installer/runtime.lock.json)。
安装包不是 GitHub 的「Code → Download ZIP」：它还要加入第三方 PHP
运行时。`tools/package-installers.php` 只收录工具运行必需的文件，不会把
整个源码仓库塞进安装包。Mac 和 Windows 安装包都用来**在开发电脑上**
编译 Linux amd64 程序；Windows 安装包不会生成 Windows exe。

Windows 脚本会自动取得所需输入并校验；Mac 仍需按下文准备锁定的运行时
和编译驱动。仅把源码 ZIP 解压不会自动得到可安装的归档。所有输入须符合
锁文件中的 SHA-256；不符时脚本停止，不能用其他版本凑数。

### Windows：从源码一条命令构包并对照

在 Windows x64 上下载 [当前仓库源码 ZIP](https://github.com/supdger/webman-aot/archive/refs/heads/main.zip)，
解压后打开 `webman-aot-main` 文件夹。这个文件夹应能直接看到 `tools`
和 `src`。在文件夹地址栏输入
`powershell` 并回车，然后原样运行：

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\build-windows-installer.ps1 -CompareRelease
```

如果你要在**以前创建的本地 Git 克隆**里运行，先确认它是没有本地修改的
`main` 分支，再在该目录执行 `git pull --ff-only origin main`；看到更新
成功后才能运行上面的命令。旧脚本没有 `-CompareRelease` 参数，直接运行
只会报“找不到参数”。如果目录不是 Git 克隆、更新失败或有本地修改，
不要强行覆盖；改用上面的当前源码 ZIP，解压到**新文件夹**运行。

**不用另外下载或解压发布安装包，也不用修改命令中的路径、版本或文件名。**
脚本读取源码里的版本号，自动找到对应的 Release，下载发布包及摘要，校验后在
`dist/source-build/` 生成本机安装包，再逐文件对照。无需预装 PHP 或
TypePHP；所需运行时与源码也由脚本下载并校验。运行时会依次显示
`[prepare]`、`[release]`、`[download]`、`[build]`、`[verify]` 和
`[compare]` 阶段；下载或打包耗时期间会定期输出进度或仍在运行的提示。
看到 `[MATCH]` 才表示内容一致；失败会报具体原因。你手工解压的只有**源码 ZIP**；脚本下载的
发布安装包留在 `dist/installer-inputs/`，自己构建的安装包留在
`dist/source-build/`，这两个安装包都不用解压。两个安装包的整体摘要
可能因压缩时间戳不同而不同，所以对照的是包内文件的内容及清单。

只想构包、不联网对照发布包时省略 `-CompareRelease`。Mac 安装包仍需
下述锁定的 Mac 运行时及编译驱动输入；这条命令只负责 Windows 安装包。

## macOS Apple Silicon：先选你要做的事

**只想在 Mac 上使用工具编译 Webman 项目：**不需要本页的源码构包步骤。
到 [v0.1.2 Release](https://github.com/supdger/webman-aot/releases/tag/v0.1.2)
下载 `webman-aot-0.1.2-macos-arm64.tar.gz`，按
[Mac 安装步骤](install-and-build.md#macos-apple-silicon)解压、安装，再回到
[首页第 2 步](../README.md#第-2-步编译你的项目)使用 `webman-aot`。

**想从本仓库源码重新制作 Mac 工具安装包：**目前还不是 Windows 那样的
一条命令。最难取得的输入是由 `static-php-cli 2.8.5` 构建、摘要与
[运行时锁文件](../installer/runtime.lock.json)完全一致的 Mac CLI PHP，
以及它对应的第三方许可文件。本仓库有锁文件、规范化脚本、编译驱动脚本和
打包脚本，**没有从空白 Mac 自动构建该 CLI 并取得许可文件的完整脚本**。
没有这两项输入，下面的打包命令不能成功；不要把已发布安装包中的 PHP
冒充独立源码构建结果。

### 1. 在源码根目录备齐输入

下面的路径都相对于本仓库根目录（能直接看到 `tools/` 和 `src/` 的目录）。
先准备这些文件；它们不会提交进 Git：

| 路径 | 从哪里来 |
| --- | --- |
| `dist/installer-inputs/php-8.4.25.tar.xz` | [`toolchain.lock.json`](../toolchain.lock.json) 的 `php-source` 官方源码归档 |
| `dist/installer-inputs/v0.9.2.tar.gz` | 同一锁文件的 `typephp-source` 源码归档 |
| `dist/installer-inputs/php-macos-upstream` | 用 `static-php-cli 2.8.5` 在锁定的 `/private/tmp/webman-aot-spc-2.8.5` 路径构建的原始 Mac CLI PHP |
| `dist/installer-inputs/source-licenses/` | **同一次** CLI 构建产出的第三方许可文件目录，不能是空目录 |

前两个公开归档可以直接在源码根目录下载；`curl` 会显示下载进度，后续脚本
仍会核对锁定的 SHA-256：

```sh
mkdir -p dist/installer-inputs
curl -fL --retry 3 \
  https://www.php.net/distributions/php-8.4.25.tar.xz \
  -o dist/installer-inputs/php-8.4.25.tar.xz
curl -fL --retry 3 \
  https://codeload.github.com/swoole/typephp/tar.gz/refs/tags/v0.9.2 \
  -o dist/installer-inputs/v0.9.2.tar.gz
```

后两项**不能靠上述下载命令得到**。如果还没有锁定的 Mac CLI 和同次
构建的许可文件，到这里就应停止：当前仓库没有可照抄的完整复建命令。
[Mac 运行时源码与重链接材料](macos-runtime-source.md)供需要审查或修改
LGPL 组件的人使用，但不是现成的安装包构建输入。

### 2. 转换已备齐的 Mac 输入

下面两条命令分别生成 `php-compiler`（打包时使用的编译驱动）和
`php-macos`（规范化后的工具运行时）。Mac 须有 Xcode 命令行工具及脚本
使用的系统构建依赖。`driver-work` 必须是新建的空目录，两个输出文件
必须事先不存在：

```sh
mkdir dist/installer-inputs/driver-work
sh tools/build-macos-compiler-driver.sh \
  dist/installer-inputs/php-8.4.25.tar.xz \
  dist/installer-inputs/php-compiler \
  dist/installer-inputs/driver-work
dist/installer-inputs/php-compiler tools/sanitize-macos-cli-runtime.php \
  dist/installer-inputs/php-macos-upstream \
  dist/installer-inputs/php-macos
```

脚本会检查 PHP 源码、原始 CLI、编译驱动的版本或摘要。摘要不符就停止，
不要关闭校验或换一个“差不多”的 PHP。重复运行前不要覆盖已有输出或
工作目录；换一个干净源码目录重新准备。

### 3. 只制作 Mac 安装包

四项原始输入和两个转换结果都备齐后，在同一源码根目录执行：

```sh
dist/installer-inputs/php-compiler tools/package-installers.php \
  --platform=macos-arm64 \
  --mac-runtime=dist/installer-inputs/php-macos \
  --mac-compiler-driver=dist/installer-inputs/php-compiler \
  --mac-runtime-license-dir=dist/installer-inputs/source-licenses \
  --php-source-archive=dist/installer-inputs/php-8.4.25.tar.xz \
  --typephp-source-archive=dist/installer-inputs/v0.9.2.tar.gz \
  --output=dist/installers \
  --revision=source-build
```

成功后应出现 `dist/installers/webman-aot-0.1.2-macos-arm64.tar.gz`，命令输出
路径、大小和 SHA-256；没有 `[ERROR]` 且退出码为 0 才算打包步骤通过。
`--platform=macos-arm64` 表示**不需要 Windows PHP ZIP，也不会生成 Windows
安装包**。想同时制作两个平台的安装包，另需锁定的 Windows PHP ZIP；
Windows 单独构包请用上面的 Windows 命令。

`dist/` 被 Git 忽略：本机生成归档**不等于已经发布**。公开分发前还须核对
第三方许可，对新归档做安装及构建验收，再由维护者上传到
[Releases](https://github.com/supdger/webman-aot/releases)。

## 验证新安装包

在相应系统上按[安装说明](install-and-build.md)从**新生成的安装包**
安装，确认 `webman-aot version` 和 `webman-aot doctor`；
再在测试 Webman 项目中执行 `webman-aot build`、`webman-aot verify`，
最后到 Linux 验证 `dist-aot/`。仅有两个归档文件不等于功能已经验收。
