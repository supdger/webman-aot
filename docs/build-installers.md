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

## 第 1 步：准备输入

在 macOS Apple Silicon 机器上准备：

1. Mac PHP 8.4.25 CLI 运行时文件。锁文件记录上游
   `static-php-cli 2.8.5` 构建器的下载地址、摘要、构建前和规范化后的
   PHP 文件摘要。使用
   [`tools/sanitize-macos-cli-runtime.php`](../tools/sanitize-macos-cli-runtime.php)
   把符合锁文件的上游 CLI PHP 规范化成打包所需文件。当前锁定的上游
   PHP 文件是在 `/private/tmp/webman-aot-spc-2.8.5` 构建的；规范化脚本
   会校验这个构建路径的固定结构，不接受任意位置新编出的不同二进制。
2. Mac 编译驱动文件。用锁文件指定的 PHP 8.4.25 源码和
   [`tools/build-macos-compiler-driver.sh`](../tools/build-macos-compiler-driver.sh)
   在 Mac ARM64 构建；脚本验证源码摘要和 PHP 版本，打包脚本再验证
   编译结果摘要。
3. 上述 Mac CLI 构建的第三方许可文件目录（例如
   `static-php-cli` 构建结果中的 `buildroot/source-licenses/`）。
   目录不能是空的；打包脚本还会从同一份锁定的 PHP 源码归档提取
   `libmbfl` 和 `libbcmath` 的 LGPL-2.1 文本。

另需准备两项跨平台输入：

4. 按锁文件中的 `archiveUrl` 下载 **PHP 官方 Windows 8.4.25 x64 ZIP**，
   保留原 ZIP；打包脚本会验证 ZIP、其中的 `php.exe` 和 `php8ts.dll`。
   Windows 安装包只收录运行所需的 PHP 文件，不捆绑 TypePHP 编译器。
5. 按 [`toolchain.lock.json`](../toolchain.lock.json) 的 `typephp-source`
   记录下载 TypePHP v0.9.2 源码归档，保留原文件。打包脚本从中提取
   GPL-3.0 许可证，并校验归档 SHA-256。

取得上述原始文件后，Mac 上的两条转换命令分别是：

```sh
mkdir -p dist/installer-inputs/driver-work
sh tools/build-macos-compiler-driver.sh \
  <php-8.4.25.tar.xz路径> \
  dist/installer-inputs/php-compiler \
  dist/installer-inputs/driver-work
php tools/sanitize-macos-cli-runtime.php \
  <static-php-cli构建的原始PHP路径> \
  dist/installer-inputs/php-macos
```

`driver-work` 必须为空，两个输出文件必须事先不存在。若脚本提示摘要
不符，应回到锁文件核对上游文件，**不要关闭校验**。

Mac CLI 的上游构建仍需 `static-php-cli` 自身的构建环境；当前仓库**没有**
一条从空白电脑自动安装依赖并完成所有输入的命令。这里的构包入口是
“已备齐锁定输入 → 生成安装包”，不是“只下载源码 → 自动得到安装包”。

## 第 2 步：运行打包脚本

在本仓库根目录执行；把尖括号中的路径替换为第 1 步得到的**真实文件**：

```sh
php tools/package-installers.php \
  --mac-runtime=<规范化后的Mac-PHP文件> \
  --mac-compiler-driver=<Mac编译驱动文件> \
  --mac-runtime-license-dir=<Mac许可文件目录> \
  --php-source-archive=<php-8.4.25.tar.xz路径> \
  --windows-runtime-archive=<PHP官方Windows原始ZIP> \
  --typephp-source-archive=<TypePHP-v0.9.2源码归档> \
  --output=dist/installers \
  --revision="$(git rev-parse HEAD)"
```

脚本成功后，`dist/installers/` 内出现两个文件：

```text
webman-aot-0.1.2-macos-arm64.tar.gz
webman-aot-0.1.2-windows-x86_64.zip
```

终端输出各文件的路径、大小及 SHA-256。输入摘要不匹配或缺许可目录时，
不会生成合格安装包。`dist/` 被 Git 忽略：**构包成功不等于 GitHub
Releases 已经发布**。公开分发前还须核对第三方许可、对新归档做安装及
构建验收，然后由维护者上传归档与对应摘要。已发布包见
[Releases](https://github.com/supdger/webman-aot/releases)。

## 第 3 步：验证新安装包

在相应系统上按[安装说明](install-and-build.md)从**新生成的安装包**
安装，确认 `webman-aot version` 和 `webman-aot doctor`；
再在测试 Webman 项目中执行 `webman-aot build`、`webman-aot verify`，
最后到 Linux 验证 `dist-aot/`。仅有两个归档文件不等于功能已经验收。
