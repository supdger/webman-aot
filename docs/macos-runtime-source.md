# macOS 运行时源码与重链接材料

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

材料用于重建或修改运行时，不保证重新链接所得文件与发布包的
SHA-256 相同。发布安装包的锁定摘要仍只认可未经修改的运行时。
