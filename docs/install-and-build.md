# 安装与构建

> 本分支的 `doctor` 自动准备尚未发布。下方 v0.1.2 安装包仍使用
> [v0.1.2 原版构建步骤](https://github.com/supdger/webman-aot/blob/v0.1.2/docs/install-and-build.md)
> 中的 `doctor --repair`；本页第 2 步的单命令流程适用于用本分支源码
> 构建的新包。发布新版并完成验收前，不应把它描述为 v0.1.2 的行为。

到 [v0.1.2 Releases 页面](https://github.com/supdger/webman-aot/releases/tag/v0.1.2)
的 **Assets** 下载你的开发电脑对应的安装包：

| 开发电脑 | 下载文件 |
| --- | --- |
| macOS Apple Silicon | `webman-aot-0.1.2-macos-arm64.tar.gz` |
| Windows x64 | `webman-aot-0.1.2-windows-x86_64.zip` |

普通安装只选上表中的一个文件。`macos-php-relink-materials.tar.gz` 是
运行时源码与重链接材料，不是安装包；`SHA256SUMS.txt` 是可选的独立
复核材料，**都不需要为正常安装下载**。

不要下载 GitHub 自动生成的「Source code (zip)」充当安装包。**正常安装
不要求手工计算摘要**：安装脚本会自动逐个验证包内文件的 SHA-256，
不一致就停止，并在通过时显示校验结果。发布者在上传前核对完整安装包
摘要；`SHA256SUMS.txt` 供需要独立复核的用户自愿使用。

安装包按**开发电脑**系统选，不是按目标 Linux 服务器选。两端都构建
Linux amd64 musl 程序，Windows 包不生成 Windows exe。安装器仅写入
当前用户的私有工具目录及命令目录，不安装 Docker 或系统级 PHP。

## 第 1 步：在开发电脑安装工具

按你现在的情况，**每个平台只选一种方法做一次**。安装脚本在安装包
解压出的文件夹里，不在 Webman 项目里。当前安装包没有双击安装入口。

### Windows x64

**方法一：刚下载 ZIP，还没有解压。** 在下载目录找到
`webman-aot-0.1.2-windows-x86_64.zip`，右键选“全部提取”。打开
解压出的文件夹，确认能直接看到 `install.ps1` 和 `payload`。在该
文件夹的地址栏输入 `powershell` 并按回车，执行：

```powershell
powershell -ExecutionPolicy Bypass -File .\install.ps1
```

**方法二：ZIP 已经解压。** 不要再解压，也不要在这个文件夹里找 ZIP。
直接打开能看到 `install.ps1` 和 `payload` 的文件夹，在地址栏输入
`powershell` 并按回车，执行**同一条**安装命令：

```powershell
powershell -ExecutionPolicy Bypass -File .\install.ps1
```

### macOS Apple Silicon

**方法一：刚下载 tar.gz，还没有解压。** 双击
`webman-aot-0.1.2-macos-arm64.tar.gz` 解压。进入能直接看到
`install.sh` 的文件夹，在该文件夹打开终端，执行：

```sh
./install.sh
```

**方法二：tar.gz 已经解压。** 不要再解压。直接进入能看到
`install.sh` 的文件夹，在该文件夹打开终端，执行**同一条**命令：

```sh
./install.sh
```

安装脚本结束时会显示两行路径，例如 Windows 上：

```text
Webman AOT installed in: E:\Users\你的用户名\AppData\Local\webman-aot
Command installed as: E:\Users\你的用户名\AppData\Local\webman-aot\bin\webman-aot.cmd
```

这表示**安装脚本已完成**。关闭并重新打开终端，执行：

```sh
webman-aot version
```

输出 `webman-aot 0.1.2` 才表示新终端也能找到并运行工具。安装阶段不会
编译 Webman 项目；下一步才进入项目目录。

## 第 2 步：编译你的 Webman 项目

在开发电脑进入你自己的后端目录：该目录内应有 `webman` 和
`composer.json`。不要在工具安装包目录或本仓库源码目录执行。

```sh
webman-aot doctor
```

首次运行若只有编译组件未准备好，`doctor` 会自动下载、校验并准备，
不需要再手动运行 `doctor --repair`。它先检查平台、磁盘、锁文件和项目；
这些检查失败时不会盲目下载。网络 TCP 探测可能受代理影响，即使探测
失败也会尝试由实际下载器连接，并以下载结果为准。准备过程显示当前组件与下载进度，
可能较久，请等命令返回。最后看到 `Result: healthy` 才继续编译：

```sh
webman-aot build --profile=saiadmin
webman-aot verify
```

普通 Webman 项目改用 `webman-aot build`。成功后，**你的项目目录**
会出现 `dist-aot/`；这里才是编译完成的 Linux 发布目录。
若看到 `Result: unhealthy` 或下载失败，不要编译；根据具体 `[ERROR]`
与末尾的 Diagnostic bundle 排查。只想检查、不允许下载时使用
`webman-aot doctor --check`；修复中断后可重试 `webman-aot doctor`，
已通过 SHA-256 校验的组件会复用。

## 第 3 步：部署 Linux 发布目录

将整个 `dist-aot/` 复制到 Linux amd64 服务器，在部署目录配置外置 `.env`，
然后进入该目录执行 `./start.sh`。数据库连接等环境参数不需要重新编译。
请按[目标机验收](linux-acceptance.md)检查启动和真实业务接口；
`webman-aot verify` 不等于目标服务器验收。

## 卸载开发电脑上的工具

卸载脚本在**安装包的解压目录**，不在 Webman 项目目录，也不在任意
PowerShell 当前目录。用文件管理器打开安装时解压出的文件夹，确认里面
能直接看到 `uninstall.ps1`（Windows）或 `uninstall.sh`（Mac）。
Windows 用户可在该文件夹的地址栏输入 `powershell` 并按回车，然后
先检查、再执行：

```sh
# macOS：在能直接看到 uninstall.sh 的目录
ls ./uninstall.sh
./uninstall.sh --purge
```

```powershell
# Windows PowerShell：在能直接看到 uninstall.ps1 的目录
Test-Path -LiteralPath .\uninstall.ps1  # 应显示 True
powershell -ExecutionPolicy Bypass -File .\uninstall.ps1 -Purge
```

如果 `Test-Path` 显示 `False`，先找到实际解压目录，不要在 Webman 项目
目录反复运行相对路径命令。卸载成功时会显示 `Webman AOT uninstalled.`；
重新打开终端后，`webman-aot` 命令应不可用。

不加 `--purge` / `-Purge` 时，会移除当前安装和命令入口，但保留部分
已下载组件与安装备份；加上后会清除 Webman AOT 的整个用户工具目录。
Mac 默认目录是 `~/Library/Application Support/webman-aot`，Windows 默认
目录是 `%LOCALAPPDATA%\webman-aot`。卸载脚本还会移除安装时加入的
命令路径；重新打开终端后生效。卸载不会删除你的 Webman 项目、项目
中的 `dist-aot/`，也不会删除下载的安装包或解压目录。

如果解压目录已经删除，从上方 Release 重新下载与你的开发电脑对应的
安装包并解压，即可取得卸载脚本。自定义安装目录的用户卸载时也要传入
相同目录参数：Mac 使用 `--home`、`--bin-dir`；Windows 使用
`-InstallRoot`、`-BinDir`，避免只卸载默认位置。

## 使用边界

想自己从本仓库源码生成上述工具安装包，请看
[从源码制作安装包](build-installers.md)。源码下载、工具安装和编译
Webman 项目是三个不同动作。

当前实测组合为 SaiAdmin 6.1.5、Webman 2.2.4、TypePHP 0.9.2 与
锁定的 PHP 8.4.25 静态 SDK。新增插件或依赖版本漂移时，未知业务
PHP 不会被静默忽略，需先处理适配失败再出包。`dist-aot` 的 `verify`
只能证明包结构、覆盖和静态链接结构；部署到目标 Linux 后仍须按
[目标机验收](linux-acceptance.md)检查启动、数据库和业务接口。
构建会为 SaiAdmin 保留空的 `plugin/saiadmin/public/export` 可写目录，
但不会把其中已有的导出文件复制进产物；`public/storage` 中的历史上传文件
也不会随包分发。部署时如需保留这些运行数据，应单独迁移，不能把构建包
当作数据备份。上述目录若含 PHP 或符号链接，构建会报错而不是静默排除。
第三方组件的许可和来源见[归属说明](../NOTICE.md)；构建与运行的
已验证范围见[验证记录](verification.md)。在未验证过的目标系统和
业务项目上，仍需执行实际部署与业务验收。
