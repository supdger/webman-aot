# macOS 运行时源码与重链接材料

## v0.2.0 macOS runtime provenance

v0.2.0 的 Mac ARM64 CLI 和独立 compiler-driver 从 PHP 8.4.25、
static-php-cli 2.8.5 的锁定输入重新构建。构建使用 `/opt/webman-aot-builder/compiler-php`、
`/usr/src/webman-aot-builder` 和 `org.webman-aot-builder.cli-php` 标识。
新 CLI 与 driver 的摘要已写入 `installer/runtime.lock.json`。

2026-09-27 在工作区生成并审计的候选输出路径为：

```text
dist/installers/v0.2.0-rename-candidate-20260927-r4/webman-aot-builder-0.2.0-macos-php-relink-materials.tar.gz
```

Release 资产名称为 `webman-aot-builder-0.2.0-macos-php-relink-materials.tar.gz`。
文件大小为 **137,607,655 bytes**，SHA-256 为
`380880904e1aaa444d1c3485eb0a2733d47bc4fd8ec6e2fd7499ed45d384eeda`。
归档内有 **59,944 个普通文件、1,447 个目录**；
`webman-aot-builder-v0.2.0-release/FILES.sha256` 列出其余 59,943 个文件，
按惯例不包含自身。所有清单条目逐项一致；路径审计未发现缺失、重复、
绝对/越界路径或符号链接。当前发布状态与下载入口以
[v0.2.0 GitHub Release 页面](https://github.com/supdger/webman-aot-builder/releases/tag/v0.2.0)
为准。

归档不是安装包，包含 patched PHP 8.4.25 CLI 的源码、Makefile、目标文件、
静态依赖和许可，独立 compiler-driver 的源码与目标文件，static-php-cli 2.8.5
上游源码快照，以及 v0.2.0 artifacts、runtime lock、构建/规范化脚本和许可证。
归档内的 `README.md`、`SOURCES.json`、`FILES.sha256` 和 `SELFTEST.md` 描述内容、
来源与操作步骤。许可证目录包括 `libmbfl`、`libbcmath` 的 LGPL-2.1 文本；PHP
及其他依赖许可随源码树和 buildroot 一并提供。

在 macOS Apple Silicon 上，从已发布 Release 取得材料后，先按 Release
列出的 SHA-256 核对归档；解压后在包含所有顶层目录的目录中验证成员清单：

```sh
shasum -a 256 webman-aot-builder-0.2.0-macos-php-relink-materials.tar.gz
shasum -a 256 -c webman-aot-builder-v0.2.0-release/FILES.sha256
```

重新链接 CLI 时，先确认 `/private/tmp/webman-aot-builder-spc-2.8.5` 不存在，
再将归档解压到 `/private/tmp`。在
`webman-aot-builder-spc-2.8.5/source/php-src/` 修改
`ext/mbstring/libmbfl/` 后执行：

```sh
make -j1 sapi/cli/php 'EXTRA_LIBS=-lssl -lcrypto -lxml2 -liconv -lzip -lz -lresolv'
```

CLI 的生成 Makefile 默认缺少静态 OpenSSL 链接库，因此该命令需要显式传入
`EXTRA_LIBS`。修改独立 compiler-driver 的 `ext/bcmath/libbcmath/` 后，在
`webman-aot-builder-driver-work-20260927/php-8.4.25/` 执行：

```sh
make -j1 sapi/cli/php
```

两条命令已在隔离解压副本实际执行：测试先将 934 个生成的构建元数据路径改到
隔离目录，然后分别修改一个 C 文件并成功重新编译/链接。修改后的 CLI SHA-256
为 `d101241902d31e7441d8afe783bad1f2860a78c73324b8006da0b7c8f30a258d`，driver
SHA-256 为 `9db22286fcc0e868a550e20ff38872b71a7d37e1486ddb4091a95f15b532b417`。
修改版没有部署，也不匹配发布锁；归档中的原始文件和原构建路径未被修改。

## 历史 v0.1.2 runtime

以下材料只描述历史 v0.1.2；路径中的 `webman-aot` 是当时的固定前缀，
不是 v0.2.0 的构建路径。新版本不复用旧二进制摘要或旧路径。

v0.1.2 的 Mac 安装包含两个 PHP 8.4.25 程序：工具使用的静态 CLI，以及
单独的编译驱动。CLI 编入 PHP 的 `libmbfl`，编译驱动编入 `libbcmath`；
这两部分采用 LGPL-2.1。各自的许可证文本已随安装包提供。

[v0.1.2 Release Assets](https://github.com/supdger/webman-aot/releases/tag/v0.1.2)
另有 `webman-aot-0.1.2-macos-php-relink-materials.tar.gz`。它不是工具
安装包，普通使用者不用下载。它包含该 CLI 的 PHP 8.4.25 源码、实际
编译出的目标文件、Makefile、静态依赖库与许可证；还包含原样的官方
`php-8.4.25.tar.xz` 和 static-php-cli 2.8.5 源码，供修改 LGPL 组件
并重新构建或链接。先按 Release 的 `SHA256SUMS.txt` 核对归档摘要。

需要重链接 CLI 时，在一台隔离的 macOS Apple Silicon 机器上操作。
构建树的 Makefile 保留了原构建路径
`/private/tmp/webman-aot-spc-2.8.5`；**若该路径已经存在，不要覆盖**，
应先换用干净测试机。确认该路径不存在后，把材料归档解压到
`/private/tmp`，修改其中 `source/php-src/ext/mbstring/libmbfl/` 的
源码，并在 `source/php-src` 目录执行 `make -j1 sapi/cli/php`。
生成的 `sapi/cli/php` 是重新链接的 CLI。归档保留原目标文件，
无需以原项目私有数据为输入。

编译驱动可用归档中的 `php-8.4.25.tar.xz` 和本仓库的
[`tools/build-macos-compiler-driver.sh`](../tools/build-macos-compiler-driver.sh)
在 macOS Apple Silicon 上先构建基线。脚本接受原始归档、一个
尚不存在的输出文件和一个已创建的空工作目录；运行后源码留在
`<工作目录>/php-8.4.25/`。随后修改该目录的
`ext/bcmath/libbcmath/` 源码，在该 PHP 源码目录执行 `make -j1`，
即可得到修改后的 `sapi/cli/php`。构建脚本只校验原始归档，
不会把修改后的程序冒充发布包的锁定二进制。

重链接或修改历史 v0.1.2 材料所得文件不保证与该发布包的 SHA-256 相同。
本页保留旧 Release 资产、路径和命令作为历史事实。
